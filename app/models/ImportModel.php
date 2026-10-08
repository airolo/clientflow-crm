<?php
/**
 * CSV import - parse, validate, plan, commit.
 *
 * Import is the most dangerous thing this app does, because it is the only path
 * that writes many rows from data the app did not create. Three rules follow from
 * that, and the whole file is shaped around them:
 *
 *  1. **Nothing is written until the user has seen the plan.** Every upload is
 *     parsed and validated and the result shown - how many rows will be created,
 *     which are duplicates, what is wrong with the ones that failed - before a
 *     single INSERT runs.
 *  2. **One transaction.** Either the rows are all written or none are. A failed
 *     import must not leave half a customer list behind.
 *  3. **The tenant comes from the session.** Never from the file or the form, so
 *     an import cannot be aimed at another workspace.
 *
 * Column names in a customer's CSV will not match ours, so headers are matched
 * against a list of aliases per field. Unrecognised columns are reported rather
 * than ignored silently: someone who exported "Company" and re-imported it
 * expecting that column to land needs to be told it did not.
 */

declare(strict_types=1);

/** Upload limits. Generous for a small business, low enough to bound the work. */
const IMPORT_MAX_BYTES     = 2097152;   // 2 MB
const IMPORT_MAX_ROWS      = 2000;
const IMPORT_MAX_FIELDS    = 60;
const IMPORT_MAX_CELL      = 2000;      // characters in one cell

/**
 * Columns each importable type accepts, as field => label.
 *
 * The label is what the review screen prints, so it is the human name rather
 * than the database column.
 */
function import_field_labels(string $type): array
{
    if ($type === 'client') {
        return [
            'company_name'   => 'Company',
            'contact_person' => 'Contact',
            'email'          => 'Email',
            'phone'          => 'Phone',
            'address'        => 'Address',
            'status'         => 'Status',
            'assigned_to'    => 'Assigned to',
            'notes'          => 'Notes',
        ];
    }
    return [
        'lead_name'       => 'Name',
        'company'         => 'Company',
        'email'           => 'Email',
        'phone'           => 'Phone',
        'lead_source'     => 'Source',
        'status'          => 'Status',
        'estimated_value' => 'Estimated value',
        'assigned_to'     => 'Assigned to',
        'notes'           => 'Notes',
    ];
}

/**
 * Header spellings accepted for each field, including the database name so a
 * re-import of our own export works unchanged.
 *
 * Matching is on a normalised form: trimmed, lowercased, non-alphanumerics
 * collapsed to a single underscore. So "Company Name", "company name" and
 * "COMPANY_NAME" all match, and "Assigned to" matches "assigned_to".
 */
function import_field_aliases(string $type): array
{
    if ($type === 'client') {
        return [
            'company_name'   => ['company_name', 'company', 'company name', 'client', 'client name', 'organisation', 'organization', 'account', 'account name'],
            'contact_person' => ['contact_person', 'contact', 'contact person', 'contact name', 'contact details', 'first name', 'forename'],
            'email'          => ['email', 'email address', 'e mail', 'mail'],
            'phone'          => ['phone', 'phone number', 'telephone', 'tel', 'mobile', 'mobile number'],
            'address'        => ['address', 'street', 'street address', 'full address', 'postal address'],
            'status'         => ['status', 'client status', 'stage'],
            'assigned_to'    => ['assigned_to', 'assigned to', 'assigned', 'owner', 'owner name', 'responsible'],
            'notes'          => ['notes', 'note', 'comments', 'comment', 'remarks'],
        ];
    }
    return [
        'lead_name'       => ['lead_name', 'lead name', 'name', 'lead', 'contact', 'contact name', 'full name'],
        'company'         => ['company', 'company name', 'organisation', 'organization', 'account', 'account name'],
        'email'           => ['email', 'email address', 'e mail', 'mail'],
        'phone'           => ['phone', 'phone number', 'telephone', 'tel', 'mobile', 'mobile number'],
        'lead_source'     => ['lead_source', 'lead source', 'source'],
        'status'          => ['status', 'lead status', 'stage'],
        'estimated_value' => ['estimated_value', 'estimated value', 'value', 'deal value', 'amount', 'expected value'],
        'assigned_to'     => ['assigned_to', 'assigned to', 'assigned', 'owner', 'owner name', 'responsible'],
        'notes'           => ['notes', 'note', 'comments', 'comment', 'remarks'],
    ];
}

/** Reduce a header to a comparable form. */
function import_normalise_header(string $header): string
{
    $header = strtolower(trim($header));
    // Strip a UTF-8 BOM and any stray whitespace characters people paste in.
    $header = str_replace("\xEF\xBB\xBF", '', $header);
    $header = (string) preg_replace('/[^a-z0-9]+/', '_', $header);
    return trim($header, '_');
}

/**
 * Work out which CSV column feeds which field.
 *
 * Returns ['map' => [field => columnIndex], 'unmatched' => [header, ...]].
 * A field with no column is simply absent from the map; the caller decides
 * whether that is fatal based on the field's required flag.
 */
function import_map_headers(array $headers, string $type): array
{
    $aliases = import_field_aliases($type);
    $map = [];
    $claimed = [];

    foreach ($aliases as $field => $names) {
        foreach ($headers as $index => $header) {
            $normal = import_normalise_header((string) $header);
            if ($normal === '' || isset($claimed[$index])) {
                continue;
            }
            if (in_array($normal, $names, true)) {
                $map[$field] = $index;
                $claimed[$index] = $field;
                break;
            }
        }
    }

    $unmatched = [];
    foreach ($headers as $index => $header) {
        if (!isset($claimed[$index])) {
            $unmatched[] = (string) $header;
        }
    }

    return ['map' => $map, 'unmatched' => $unmatched];
}

/** Fields that must be present for a row to be importable at all. */
function import_required_fields(string $type): array
{
    return $type === 'client'
        ? ['company_name', 'contact_person']
        : ['lead_name'];
}

/**
 * Guess the delimiter.
 *
 * Comma by default, but a semicolon is what Excel writes when the user's locale
 * uses it for decimals - and this app has EUR, PLN and BRL in its currency list,
 * so a semicolon file is a realistic upload, not a hypothetical one. Tab is
 * third because people export from spreadsheets that way.
 */
function import_detect_delimiter(string $firstLine): string
{
    $candidates = [',' => 0, ';' => 0, "\t" => 0, '|' => 0];
    $inQuotes = false;
    $length = strlen($firstLine);
    for ($i = 0; $i < $length; $i++) {
        $ch = $firstLine[$i];
        if ($ch === '"') {
            $inQuotes = !$inQuotes;
            continue;
        }
        if ($inQuotes) {
            continue;
        }
        if (isset($candidates[$ch])) {
            $candidates[$ch]++;
        }
    }

    arsort($candidates);
    // Only switch away from comma when another delimiter is clearly the column
    // separator - a comma inside an address on a single-column file must not
    // convince us the file is comma-delimited with three columns.
    if ($candidates[','] === 0 && $candidates[';'] > 0) {
        return ';';
    }
    if ($candidates[','] === 0 && $candidates["\t"] > 0) {
        return "\t";
    }
    return ',';
}

/**
 * Read an uploaded file into rows.
 *
 * @return array{ok: bool, error?: string, headers?: array, rows?: array, delimiter?: string}
 */
function import_read_upload(array $file): array
{
    if (!isset($file['error']) || is_array($file['error'])) {
        return ['ok' => false, 'error' => 'No file was uploaded.'];
    }

    switch ((int) $file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            return ['ok' => false, 'error' => 'Choose a CSV file to import.'];
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return ['ok' => false, 'error' => 'That file is larger than the ' . import_human_bytes(IMPORT_MAX_BYTES) . ' limit.'];
        default:
            return ['ok' => false, 'error' => 'The upload did not complete. Try again.'];
    }

    if ((int) ($file['size'] ?? 0) > IMPORT_MAX_BYTES) {
        return ['ok' => false, 'error' => 'That file is larger than the ' . import_human_bytes(IMPORT_MAX_BYTES) . ' limit.'];
    }

    $name = (string) ($file['name'] ?? '');
    // Extension is a convenience check, not security: the content is parsed as
    // text and every value is bound, so a mislabelled file cannot execute.
    if ($name !== '' && strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) !== 'csv') {
        return ['ok' => false, 'error' => 'That does not look like a .csv file.'];
    }

    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'The upload could not be read.'];
    }

    $handle = fopen($tmp, 'rb');
    if ($handle === false) {
        return ['ok' => false, 'error' => 'The uploaded file could not be opened.'];
    }

    $firstLine = (string) fgets($handle);
    if (trim($firstLine) === '') {
        fclose($handle);
        return ['ok' => false, 'error' => 'That file is empty.'];
    }
    $delimiter = import_detect_delimiter($firstLine);
    rewind($handle);

    $headers = fgetcsv($handle, 0, $delimiter, '"', '');
    if ($headers === false || $headers === [null]) {
        fclose($handle);
        return ['ok' => false, 'error' => 'That file has no header row.'];
    }

    // A BOM before the first header name stops "Company" being recognised.
    if (isset($headers[0])) {
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $headers[0]) ?? (string) $headers[0];
    }

    $rows = [];
    $lineNumber = 1;
    while (($record = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
        $lineNumber++;
        // fgetcsv returns [null] for a blank line, which is not an error.
        if ($record === [null] || $record === false) {
            continue;
        }
        if (count($headers) > IMPORT_MAX_FIELDS) {
            fclose($handle);
            return ['ok' => false, 'error' => 'That file has more than ' . IMPORT_MAX_FIELDS . ' columns.'];
        }
        // Ignore trailing blank lines and anything entirely empty.
        $nonEmpty = false;
        foreach ($record as $cell) {
            if (trim((string) $cell) !== '') {
                $nonEmpty = true;
                break;
            }
        }
        if (!$nonEmpty) {
            continue;
        }

        foreach ($record as $i => $cell) {
            if (strlen((string) $cell) > IMPORT_MAX_CELL) {
                fclose($handle);
                return ['ok' => false, 'error' => 'Row ' . $lineNumber . ' has a value longer than ' . IMPORT_MAX_CELL . ' characters.'];
            }
        }

        $rows[] = ['line' => $lineNumber, 'cells' => $record];

        if (count($rows) > IMPORT_MAX_ROWS) {
            fclose($handle);
            return ['ok' => false, 'error' => 'That file has more than ' . IMPORT_MAX_ROWS . ' data rows. Import it in smaller batches.'];
        }
    }
    fclose($handle);

    return [
        'ok'        => true,
        'headers'   => $headers,
        'rows'      => $rows,
        'delimiter' => $delimiter,
    ];
}

/** "2 MB" rather than "2097152", for a message a person has to act on. */
function import_human_bytes(int $bytes): string
{
    return $bytes >= 1048576
        ? round($bytes / 1048576, 1) . ' MB'
        : (int) round($bytes / 1024) . ' KB';
}

/**
 * Turn "£1,234.56" into 1234.56.
 *
 * A money column exported from a spreadsheet almost always arrives decorated.
 * Stripping currency symbols and thousands separators is what makes a value
 * importable rather than rejected for being 1,234.56.
 */
function import_parse_money(string $raw): ?float
{
    $clean = trim($raw);
    if ($clean === '') {
        return null;
    }
    // Keep digits, one dot, and a leading minus. Everything else - symbols,
    // thousands separators, spaces, a trailing "GBP" - is noise.
    $clean = (string) preg_replace('/[^0-9.\-]/', '', $clean);
    if ($clean === '' || $clean === '-' || $clean === '.') {
        return null;
    }
    // A stray second dot (1.234.56) means thousands separators that were dots.
    $lastDot = strrpos($clean, '.');
    if ($lastDot !== false && strrpos($clean, '.') !== strpos($clean, '.')) {
        $clean = str_replace('.', '', substr($clean, 0, $lastDot)) . substr($clean, $lastDot);
    }
    if (!is_numeric($clean)) {
        return null;
    }
    return (float) $clean;
}

/**
 * Match an "assigned to" cell to a user in this workspace.
 *
 * A miss is a warning, never an error: someone exporting from another system
 * will have names we do not have, and refusing the whole row over an owner is
 * unhelpful. The row is imported unassigned and the review screen says so.
 */
function import_find_user(string $name): ?int
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    $stmt = db()->prepare(
        'SELECT id FROM users
         WHERE tenant_id = ? AND is_active = 1
           AND (LOWER(name) = ? OR LOWER(email) = ?)
         ORDER BY id ASC LIMIT 1'
    );
    $stmt->execute([tenant_id(), mb_strtolower($name), mb_strtolower($name)]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int) $id;
}

/** Normalise a status/source cell: "In Progress" -> "in_progress". */
function import_normalise_option(string $raw): string
{
    $value = strtolower(trim($raw));
    $value = (string) preg_replace('/[^a-z0-9]+/', '_', $value);
    return trim($value, '_');
}

/**
 * Build the plan: one entry per row, with its values, errors and warnings.
 *
 * Errors block a row. Warnings do not - they describe something that was changed
 * or ignored on the way in, and the row still imports.
 *
 * @return array{
 *   rows: array<int, array{line:int, data:array, errors:array, warnings:array, action:string}>,
 *   summary: array
 * }
 */
function import_build_plan(array $rows, array $map, string $type): array
{
    $required = import_required_fields($type);
    $labels = import_field_labels($type);

    // Defaults for columns the file does not carry, so the row is always
    // complete enough to pass the model's own validator.
    $base = $type === 'client'
        ? ['status' => 'prospect', 'notes' => '']
        : ['status' => 'new', 'lead_source' => 'other', 'estimated_value' => 0, 'notes' => ''];

    // Existing values, for duplicate detection. Fetched once, not per row.
    $existing = import_existing_index($type);

    $seenEmail = [];
    $plan = [];
    $summary = [
        'total' => 0, 'ready' => 0, 'errors' => 0, 'duplicates' => 0, 'warnings' => 0,
        'missing_required' => [],
    ];

    foreach ($rows as $row) {
        $cells = $row['cells'];
        $line  = $row['line'];
        $errors = [];
        $warnings = [];

        $data = $base;

        // A required column that the file simply does not have is a whole-file
        // problem, so it is reported once at the top rather than on every row.
        // The FIELD NAME goes in, not its label: this function returns
        // identifiers and lets the view do the presentation. Storing the label
        // here meant the view looked up a label and printed an empty string,
        // producing "That file has no  column".
        foreach ($required as $field) {
            if (!isset($map[$field]) && !in_array($field, $summary['missing_required'], true)) {
                $summary['missing_required'][] = $field;
            }
        }

        foreach ($map as $field => $index) {
            $value = trim((string) ($cells[$index] ?? ''));
            if ($value === '') {
                continue;
            }

            switch ($field) {
                case 'status':
                case 'lead_source':
                    $normalised = import_normalise_option($value);
                    $allowed = $field === 'status'
                        ? ($type === 'client' ? client_statuses() : lead_statuses())
                        : lead_sources();
                    if (in_array($normalised, $allowed, true)) {
                        $data[$field] = $normalised;
                    } else {
                        $errors[] = 'Unknown ' . strtolower($labels[$field]) . ' "' . $value . '". Valid: ' . implode(', ', $allowed) . '.';
                    }
                    break;

                case 'estimated_value':
                    $money = import_parse_money($value);
                    if ($money === null) {
                        $errors[] = 'Could not read "' . $value . '" as a number.';
                        break;
                    }
                    // Assign first, then decide whether to mention it. An earlier
                    // version warned in an elseif branch without assigning, so
                    // every value that needed cleaning - which is most real
                    // spreadsheets - imported as 0 with only a warning to say so.
                    $data[$field] = $money;
                    // Only note it when the number actually changed, or the
                    // warning list fills with noise about values that were
                    // already plain numbers.
                    if ($money !== (float) $value) {
                        $warnings[] = 'Read "' . $value . '" as ' . number_format($money, 2) . '.';
                    }
                    break;

                case 'assigned_to':
                    $userId = import_find_user($value);
                    if ($userId === null) {
                        $warnings[] = 'No staff member called "' . $value . '", so this is left unassigned.';
                    } else {
                        $data[$field] = $userId;
                    }
                    break;

                default:
                    $data[$field] = $value;
            }
        }

        // Required fields still missing after mapping.
        foreach ($required as $field) {
            if (($data[$field] ?? '') === '' && !isset($map[$field])) {
                $errors[] = 'The file has no "' . $labels[$field] . '" column.';
            } elseif (($data[$field] ?? '') === '') {
                $errors[] = $labels[$field] . ' is blank.';
            }
        }

        // Duplicate inside the file.
        $email = strtolower((string) ($data['email'] ?? ''));
        if ($email !== '') {
            if (isset($seenEmail[$email])) {
                $errors[] = 'Same email as row ' . $seenEmail[$email] . '.';
            } else {
                $seenEmail[$email] = $line;
            }
        }

        // Duplicate of something already in the workspace.
        $key = import_duplicate_key($type, $data);
        if ($key !== null && isset($existing[$key])) {
            $warnings[] = 'Looks like the existing ' . import_field_labels($type)[$type === 'client' ? 'company_name' : 'lead_name']
                . ' "' . $existing[$key] . '" - it will be skipped unless you tick "also import matches".';
            $data['__duplicate_of'] = $key;
        }

        // Any remaining length problem is caught by the model's own validator;
        // run it now so the review screen shows the same errors the write would.
        if (!$errors) {
            foreach (($type === 'client' ? client_validate($data) : lead_validate($data)) as $message) {
                $errors[] = (string) $message;
            }
        }

        $action = $errors ? 'skip' : 'import';
        $summary['total']++;
        if ($errors) {
            $summary['errors']++;
        } else {
            $summary['ready']++;
        }
        if (isset($data['__duplicate_of'])) {
            $summary['duplicates']++;
        }
        $summary['warnings'] += count($warnings);

        $plan[] = [
            'line'     => $line,
            'data'     => $data,
            'errors'   => $errors,
            'warnings' => $warnings,
            'action'   => $action,
        ];
    }

    return ['rows' => $plan, 'summary' => $summary];
}

/** A comparable key for "is this the same record as something we already have". */
function import_duplicate_key(string $type, array $data): ?string
{
    if ($type === 'client') {
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        if ($email !== '') {
            return 'e:' . $email;
        }
        $company = trim((string) ($data['company_name'] ?? ''));
        $contact = trim((string) ($data['contact_person'] ?? ''));
        if ($company !== '' && $contact !== '') {
            return 'n:' . mb_strtolower($company) . '|' . mb_strtolower($contact);
        }
        return null;
    }

    $email = strtolower(trim((string) ($data['email'] ?? '')));
    if ($email !== '') {
        return 'e:' . $email;
    }
    $name = trim((string) ($data['lead_name'] ?? ''));
    return $name !== '' ? 'n:' . mb_strtolower($name) : null;
}

/**
 * Index what this workspace already has, so duplicates can be spotted.
 *
 * @return array key => display label
 */
function import_existing_index(string $type): array
{
    $index = [];

    if ($type === 'client') {
        $stmt = db()->prepare(
            'SELECT company_name, contact_person, email FROM clients
             WHERE tenant_id = ? AND deleted_at IS NULL'
        );
    } else {
        $stmt = db()->prepare(
            'SELECT lead_name, email FROM leads
             WHERE tenant_id = ? AND deleted_at IS NULL'
        );
    }
    $stmt->execute([tenant_id()]);

    foreach ($stmt->fetchAll() as $row) {
        $email = strtolower(trim((string) ($row['email'] ?? '')));
        if ($email !== '') {
            $index['e:' . $email] = $type === 'client'
                ? (string) $row['company_name']
                : (string) $row['lead_name'];
        }
        if ($type === 'client') {
            $company = trim((string) ($row['company_name'] ?? ''));
            $contact = trim((string) ($row['contact_person'] ?? ''));
            if ($company !== '' && $contact !== '') {
                $index['n:' . mb_strtolower($company) . '|' . mb_strtolower($contact)] = $company;
            }
        } else {
            $name = trim((string) $row['lead_name']);
            if ($name !== '') {
                $index['n:' . mb_strtolower($name)] = $name;
            }
        }
    }

    return $index;
}

/**
 * Write the planned rows.
 *
 * One transaction: an import either lands completely or not at all. The
 * tenant_id comes from the session via the model's create function, so it
 * cannot be redirected by anything in the file.
 *
 * @return array ['imported' => int, 'skipped' => int, 'failed' => string[]]
 */
function import_commit(array $plan, string $type, bool $includeDuplicates = false): array
{
    $pdo = db();
    $create = $type === 'client' ? 'client_create' : 'lead_create';

    $imported = 0;
    $skipped = 0;
    $failed = [];

    $pdo->beginTransaction();
    try {
        foreach ($plan as $row) {
            if ($row['action'] === 'skip') {
                $skipped++;
                continue;
            }
            if (!$includeDuplicates && isset($row['data']['__duplicate_of'])) {
                $skipped++;
                continue;
            }

            $data = $row['data'];
            unset($data['__duplicate_of']);

            // created_by is the person doing the import, not whoever exported
            // the file. The file has no say in it.
            $data['created_by'] = current_user_id();

            try {
                $create($data);
                $imported++;
            } catch (Throwable $e) {
                // Row-level failure is recorded, not fatal: one bad row should
                // not abandon the other 400. The transaction still commits, and
                // the failures are reported on screen.
                $failed[] = 'Row ' . $row['line'] . ': ' . $e->getMessage();
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    // One summary row, logged after the commit so it can only exist if the
    // import actually landed. Each individual row is already logged as a
    // 'create' by the model functions above, so this deliberately does not
    // repeat them - it records the totals of one import action, which is what
    // someone reconstructing a Tuesday afternoon actually needs.
    audit_record(
        'import',
        $type,
        null,
        ucfirst($type) . ' import',
        [[
            'field' => 'result',
            'label' => 'Result',
            'from'  => null,
            'to'    => $imported . ' imported, ' . $skipped . ' skipped'
                . ($failed === [] ? '' : ', ' . count($failed) . ' failed'),
        ]]
    );

    return ['imported' => $imported, 'skipped' => $skipped, 'failed' => $failed];
}