<?php
/**
 * Deal model - the sales pipeline.
 */

declare(strict_types=1);

/** Every deal grouped by stage, for the pipeline board. */
function deal_board(?int $assignedTo = null): array
{
    $sql = 'SELECT d.*, c.company_name AS client_name, c.id AS client_id_ref,
                   l.lead_name, l.company AS lead_company, u.name AS owner_name
            FROM deals d
            LEFT JOIN clients c ON c.id = d.client_id AND c.tenant_id = d.tenant_id
            LEFT JOIN leads   l ON l.id = d.lead_id AND l.tenant_id = d.tenant_id
             LEFT JOIN users   u ON u.id = d.assigned_to AND u.tenant_id = d.tenant_id
             WHERE d.tenant_id = ?
               AND d.deleted_at IS NULL
               -- A deal whose client or lead is in the bin is hidden with it,
               -- so the board never offers something that cannot be opened.
               AND (d.client_id IS NULL OR c.deleted_at IS NULL)
               AND (d.lead_id   IS NULL OR l.deleted_at IS NULL)';
    $params = [tenant_id()];

    if ($assignedTo && $assignedTo > 0) {
        $sql .= ' AND d.assigned_to = ?';
        $params[] = $assignedTo;
    }

    $sql .= ' ORDER BY d.updated_at DESC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $board = array_fill_keys(deal_stages(), []);
    foreach ($stmt->fetchAll() as $deal) {
        $board[$deal['stage']][] = $deal;
    }
    return $board;
}

/** Totals per stage for the board header. */
function deal_stage_totals(): array
{
    $stmt = db()->prepare(
        'SELECT stage, COUNT(*) AS deal_count, COALESCE(SUM(value),0) AS total_value
         FROM deals WHERE tenant_id = ? AND deleted_at IS NULL GROUP BY stage'
    );
    $stmt->execute([tenant_id()]);
    $rows = $stmt->fetchAll();

    $totals = [];
    foreach (deal_stages() as $stage) {
        $row = ['deal_count' => 0, 'total_value' => 0];
        foreach ($rows as $candidate) {
            if ($candidate['stage'] === $stage) {
                $row = $candidate;
                break;
            }
        }
        $totals[$stage] = [
            'deal_count'  => (int) $row['deal_count'],
            'total_value' => (float) $row['total_value'],
        ];
    }
    return $totals;
}

function deal_find(int $id): ?array
{
    $stmt = db()->prepare(
        'SELECT d.*, c.company_name AS client_name, l.lead_name, l.company AS lead_company,
                u.name AS owner_name, cu.name AS creator_name
         FROM deals d
         LEFT JOIN clients c  ON c.id = d.client_id AND c.tenant_id = d.tenant_id
         LEFT JOIN leads   l  ON l.id = d.lead_id AND l.tenant_id = d.tenant_id
         LEFT JOIN users   u  ON u.id = d.assigned_to AND u.tenant_id = d.tenant_id
         LEFT JOIN users   cu ON cu.id = d.created_by AND cu.tenant_id = d.tenant_id
         WHERE d.tenant_id = ? AND d.id = ? AND d.deleted_at IS NULL LIMIT 1'
    );
    $stmt->execute([tenant_id(), $id]);
    return $stmt->fetch() ?: null;
}

function deal_create(array $data): int
{
    $stmt = db()->prepare(
        'INSERT INTO deals (tenant_id, deal_title, client_id, lead_id, value, stage, expected_close_date, assigned_to, notes, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        tenant_id(),
        $data['deal_title'],
        $data['client_id'] ?: null,
        $data['lead_id'] ?: null,
        (float) ($data['value'] ?? 0),
        $data['stage'],
        null_if_empty($data['expected_close_date'] ?? null),
        $data['assigned_to'] ?: null,
        null_if_empty($data['notes'] ?? null),
        $data['created_by'],
    ]);
    return (int) db()->lastInsertId();
}

function deal_update(int $id, array $data): void
{
    $stmt = db()->prepare(
        'UPDATE deals
         SET deal_title = ?, client_id = ?, lead_id = ?, value = ?, stage = ?,
             expected_close_date = ?, assigned_to = ?, notes = ?
         WHERE tenant_id = ? AND id = ?'
    );
    $stmt->execute([
        $data['deal_title'],
        $data['client_id'] ?: null,
        $data['lead_id'] ?: null,
        (float) ($data['value'] ?? 0),
        $data['stage'],
        null_if_empty($data['expected_close_date'] ?? null),
        $data['assigned_to'] ?: null,
        null_if_empty($data['notes'] ?? null),
        tenant_id(),
        $id,
    ]);
}

/** Move a deal to another stage (pipeline drag action). */
function deal_move(int $id, string $stage): void
{
    $stmt = db()->prepare('UPDATE deals SET stage = ? WHERE tenant_id = ? AND id = ? AND deleted_at IS NULL');
    $stmt->execute([$stage, tenant_id(), $id]);
}

/** Stamp a deal as deleted. It keeps its row, value and history. */
function deal_delete(int $id): void
{
    soft_delete_row('deal', $id);
}

function deal_restore(int $id): void
{
    soft_delete_restore('deal', $id);
}

/** Dashboard figures. */
function deal_count_open(): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM deals WHERE tenant_id = ? AND deleted_at IS NULL
         AND stage IN ("new_lead","contacted","proposal","negotiation")'
    );
    $stmt->execute([tenant_id()]);
    return (int) $stmt->fetchColumn();
}

function deal_count_won(): int
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM deals WHERE tenant_id = ? AND deleted_at IS NULL AND stage = "won"');
    $stmt->execute([tenant_id()]);
    return (int) $stmt->fetchColumn();
}

function deal_value_open(): float
{
    $stmt = db()->prepare(
        'SELECT COALESCE(SUM(value),0) FROM deals WHERE tenant_id = ? AND deleted_at IS NULL
         AND stage IN ("new_lead","contacted","proposal","negotiation")'
    );
    $stmt->execute([tenant_id()]);
    return (float) $stmt->fetchColumn();
}

function deal_value_by_stage(): array
{
    $stmt = db()->prepare(
        'SELECT stage, COUNT(*) AS deal_count, COALESCE(SUM(value),0) AS total_value
         FROM deals WHERE tenant_id = ? AND deleted_at IS NULL GROUP BY stage'
    );
    $stmt->execute([tenant_id()]);
    return $stmt->fetchAll(PDO::FETCH_UNIQUE);
}

function deal_value_won_lost(): array
{
    $stmt = db()->prepare(
        'SELECT
            COALESCE(SUM(CASE WHEN stage = "won"  THEN value END), 0) AS won_value,
            COALESCE(SUM(CASE WHEN stage = "lost" THEN value END), 0) AS lost_value,
            COALESCE(SUM(CASE WHEN stage = "won"  THEN 1 END), 0)      AS won_count,
            COALESCE(SUM(CASE WHEN stage = "lost" THEN 1 END), 0)      AS lost_count
         FROM deals WHERE tenant_id = ? AND deleted_at IS NULL'
    );
    $stmt->execute([tenant_id()]);
    $row = $stmt->fetch();

    return [
        'won_value'  => (float) $row['won_value'],
        'lost_value' => (float) $row['lost_value'],
        'won_count'  => (int) $row['won_count'],
        'lost_count' => (int) $row['lost_count'],
    ];
}

/** Top open deals for the dashboard. */
function deal_top_open(int $limit = 5): array
{
    $stmt = db()->prepare(
        'SELECT d.*, c.company_name AS client_name, u.name AS owner_name
         FROM deals d
         LEFT JOIN clients c ON c.id = d.client_id AND c.tenant_id = d.tenant_id
         LEFT JOIN users   u ON u.id = d.assigned_to AND u.tenant_id = d.tenant_id
         WHERE d.tenant_id = ? AND d.deleted_at IS NULL
           AND d.stage IN ("new_lead","contacted","proposal","negotiation")
           AND (d.client_id IS NULL OR c.deleted_at IS NULL)
         ORDER BY d.value DESC
         LIMIT ?'
    );
    $stmt->bindValue(1, tenant_id(), PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Won deals, newest first (used on the reports page). */
function deal_won_list(int $limit = 10): array
{
    $stmt = db()->prepare(
        'SELECT d.*, c.company_name AS client_name, u.name AS owner_name
         FROM deals d
         LEFT JOIN clients c ON c.id = d.client_id AND c.tenant_id = d.tenant_id
         LEFT JOIN users   u ON u.id = d.assigned_to AND u.tenant_id = d.tenant_id
         WHERE d.tenant_id = ? AND d.deleted_at IS NULL
           AND d.stage = "won"
           AND (d.client_id IS NULL OR c.deleted_at IS NULL)
         ORDER BY d.updated_at DESC
         LIMIT ?'
    );
    $stmt->bindValue(1, tenant_id(), PDO::PARAM_INT);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Same as client_deals() but from the lead side (after conversion). */
function lead_deals(int $leadId): array
{
    $stmt = db()->prepare(
        'SELECT d.*, c.company_name AS client_name, u.name AS owner_name
         FROM deals d
         LEFT JOIN clients c ON c.id = d.client_id AND c.tenant_id = d.tenant_id
         LEFT JOIN users   u ON u.id = d.assigned_to AND u.tenant_id = d.tenant_id
         WHERE d.tenant_id = ? AND d.lead_id = ? AND d.deleted_at IS NULL
         ORDER BY d.created_at DESC'
    );
    $stmt->execute([tenant_id(), $leadId]);
    return $stmt->fetchAll();
}

function deal_validate(array $data): array
{
    $errors = [];

    if (($data['deal_title'] ?? '') === '') {
        $errors['deal_title'] = 'Deal title is required.';
    }

    if (!is_valid_option($data['stage'] ?? '', deal_stages())) {
        $errors['stage'] = 'Choose a valid pipeline stage.';
    }

    if ((int) ($data['client_id'] ?? 0) <= 0 && (int) ($data['lead_id'] ?? 0) <= 0) {
        $errors['client_id'] = 'Link the deal to a client or a lead.';
    }

    $value = (float) ($data['value'] ?? 0);
    if ($value < 0) {
        $errors['value'] = 'Deal value cannot be negative.';
    } elseif ($value > 9999999999.99) {
        $errors['value'] = 'Deal value is unrealistically large.';
    }

    $close = trim((string) ($data['expected_close_date'] ?? ''));
    if ($close !== '') {
        $parsed = DateTime::createFromFormat('Y-m-d', $close);
        if (!$parsed || $parsed->format('Y-m-d') !== $close) {
            $errors['expected_close_date'] = 'Enter a valid close date.';
        }
    }

    return $errors + length_errors([
        'deal_title' => [$data['deal_title'] ?? '', 180, 'Deal title'],
        'notes'      => [$data['notes'] ?? '', 2000, 'Notes'],
    ]);
}