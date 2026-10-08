<?php
/**
 * Public landing page.
 *
 * This is index.php, so Apache serves it for the site root. It is the only
 * marketing page in the project and it is deliberately NOT behind
 * require_login(): a visitor who has never heard of ClientFlow should see what
 * it is before being asked to log in.
 *
 * Two rules keep it honest:
 *   1. It renders no business data. Everything here is static markup, so the
 *      page cannot leak a customer's details by being indexed or cached.
 *   2. It claims only what the app actually does. Where something is missing it
 *      says so plainly - see the Integrations and FAQ tabs.
 *
 * It does not reuse views/header.php, which assumes a signed-in user, and it
 * loads its own stylesheet so the app's stylesheet stays untouched.
 */

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

// Only reached so an already-signed-in visitor gets "Open dashboard" as the
// primary call to action instead of "Sign in".
$isSignedIn = is_logged_in();

// The pipeline stages and lead statuses are read from the single place that
// defines them, so this page cannot drift from the app's real workflow.
$pipelineStages = deal_stages();
$leadStatuses   = lead_statuses();

/** One line per feature, grouped so the tab renders a consistent card grid. */
$featureGroups = [
    [
        'title' => 'Contacts and pipeline',
        'icon'  => 'bi-people-fill',
        'items' => [
            'Clients with company, named contact, email, phone, address and notes',
            'Leads with a source, an owner and a status that runs to won or lost',
            'A Kanban pipeline of deals in six stages, with per-stage totals',
            'Converting a lead creates a deal that keeps pointing back at it',
        ],
    ],
    [
        'title' => 'Follow-ups',
        'icon'  => 'bi-check2-square',
        'items' => [
            'Tasks with a due date, a priority and an assignee',
            'One-click complete and reopen, stamping when it was finished',
            'An overdue-only filter, plus a dashboard panel for what is due',
            'Calls, emails, meetings and notes logged against any client or lead',
        ],
    ],
    [
        'title' => 'Finding things',
        'icon'  => 'bi-search',
        'items' => [
            'Search across company, contact, email and phone',
            'Filter by status, owner, source, priority and date range',
            'Sortable columns with an allow-list, so the query cannot be rewritten',
            'Paginated lists throughout',
        ],
    ],
    [
        'title' => 'Reporting',
        'icon'  => 'bi-bar-chart-fill',
        'items' => [
            'Win rate by count and by value, plus a weighted forecast',
            'Monthly activity, new clients and new leads as a time series',
            'Team performance and clients-per-owner breakdowns',
            'Won versus lost, lead sources and activity mix',
        ],
    ],
    [
        'title' => 'People and permissions',
        'icon'  => 'bi-person-badge-fill',
        'items' => [
            'Admin and staff roles, with staff limited to their own records',
            'Reading and writing are gated separately, so access can be relaxed',
            'Ownership checks re-run in every POST handler, not just the UI',
            'Nobody is offered an Edit or Delete button they cannot use',
        ],
    ],
    [
        'title' => 'Safety net',
        'icon'  => 'bi-shield-lock-fill',
        'items' => [
            'Deleting is reversible - records move to a recycle bin',
            'Restoring a client brings its deals, tasks and history with it',
            'Every sign-in attempt is recorded and failures are throttled',
            'A backup script, because database.sql drops tables on import',
        ],
    ],
];

/** Screenshots, captured from this running app. */
$screenshots = [
    'dashboard' => ['file' => 'dashboard.png', 'caption' => 'Dashboard - headline figures, pipeline snapshot, tasks due today and recent activity'],
    'clients'   => ['file' => 'clients.png',   'caption' => 'Clients - search, filters, sortable columns and pagination'],
    'client'    => ['file' => 'client.png',    'caption' => 'Client detail - deals, tasks and the full interaction history on one page'],
    'pipeline'  => ['file' => 'pipeline.png',  'caption' => 'Pipeline - deals grouped into six stage columns'],
    'reports'   => ['file' => 'reports.png',   'caption' => 'Reports - win rate, weighted forecast, growth and team performance'],
    'recycle'   => ['file' => 'recycle.png',   'caption' => 'Recycle bin - deleted records with restore, and who removed them'],
];

/** Honest integrations: what ships, and what plainly does not. */
$stackShips = [
    'PHP 8'        => 'Plain PHP with no framework and no Composer dependencies',
    'MySQL / MariaDB' => 'InnoDB, foreign keys, utf8mb4',
    'Apache'       => 'Runs on any LAMP stack, not just XAMPP',
    'Bootstrap 5'  => 'Vendored under assets/vendor, so nothing is fetched from a CDN',
    'Bootstrap Icons' => 'Also vendored, fonts included',
    'Vanilla JS'   => 'One small file, no jQuery, no build step',
    'CSV import and export' => 'Export on every list screen; import shows exactly what would change before it saves anything',
];

$stackPlanned = [
    'Sending email'        => 'Activities can record a call or email that happened, but nothing is sent from the app',
    'Email verification'   => 'A workspace can be created against any address, because signup sends no mail yet',
    'File attachments'     => 'Not built',
    'REST API'             => 'No public API, so nothing can call into this from another tool',
    'Webhooks'             => 'Not built',
    'Calendar sync'        => 'Not built',
];

/** FAQ. The "not yet" answers are the point - see the note above. */
$faq = [
    [
        'q' => 'Can I export my data?',
        'a' => 'Yes - every list screen has an export button, for clients, leads, deals, tasks and activities. The file is scoped to your workspace only, and it is your whole workspace, not just the page you were looking at. There is also <code>tools/backup.ps1</code>, which dumps the whole database to a timestamped file and refuses to write inside the web root.',
    ],
    [
        'q' => 'Can I bring my data in from a spreadsheet?',
        'a' => 'Yes. Upload a CSV and you get a review screen first: how many rows will be created, which look like records you already have, and exactly what is wrong with each row that failed. Nothing is saved until you confirm. Column headings are matched loosely, so <code>Company Name</code> and <code>company_name</code> both work, and a file exported from here comes straight back in.',
    ],
    [
        'q' => 'Does it send email or integrate with my inbox?',
        'a' => 'No. You can log a call, an email, a meeting or a note against a client or lead, but the app sends nothing and reads no mailbox. Email sending and mailbox sync are both unbuilt.',
    ],
    [
        'q' => 'What happens if I delete a client?',
        'a' => 'It moves to the recycle bin rather than being destroyed, and its deals, tasks and activity history stay intact and hidden. An administrator can restore the client and everything attached to it comes straight back. Only "Delete forever" is irreversible, and it asks you to type the record name first.',
    ],
    [
        'q' => 'Is it multi-user?',
        'a' => 'Yes, with two roles. Admins can see and edit everything; staff are limited to records they created or are assigned to. That applies to editing and to opening a record\'s detail page. Lists and reports deliberately stay shared across the team.',
    ],
    [
        'q' => 'Does it need an internet connection?',
        'a' => 'No. Bootstrap, the icons and the fonts are all vendored into the project, so the app makes no third-party requests at any point - not even on an error page.',
    ],
    [
        'q' => 'What does it need to run?',
        'a' => 'XAMPP or any LAMP stack: PHP 8, MySQL 5.7 or newer, and Apache. Import <code>database.sql</code> and sign in. There is nothing to install and no build step.',
    ],
    [
        'q' => 'Is there an audit log?',
        'a' => 'Yes, and it records values rather than just events. Every create, edit, delete, restore and sign-in is logged with who did it and when, and an edit records the value of each field before and after - not just "record 42 was updated". Entries are kept permanently and cannot be edited or deleted from the app.',
    ],
    [
        'q' => 'Can more than one person work at a company?',
        'a' => 'A company record holds one named contact, with their email and phone. There is no separate contacts table yet, so tracking several people at one company means separate client records. Multiple contacts per account is a real gap in a production CRM.',
    ],
    [
        'q' => 'How is this licensed?',
        'a' => 'It is provided as-is for learning and portfolio use, not under an open-source licence. Bootstrap and Bootstrap Icons are MIT licensed and vendored under <code>assets/vendor/</code>.',
    ],
];

$pageTitle = 'ClientFlow CRM - a small, complete CRM for a small business';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <meta name="description" content="ClientFlow is a CRM built with plain PHP and MySQL: clients, leads, a Kanban pipeline, tasks, activity history and reports, with a recycle bin and real sign-in hardening.">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 16 16'><text y='14' font-size='14'>&#128200;</text></svg>">
    <link href="<?= e(url('assets/vendor/css/bootstrap.min.css')) ?>" rel="stylesheet">
    <link href="<?= e(url('assets/vendor/css/bootstrap-icons.min.css')) ?>" rel="stylesheet">
    <!-- style.css first: it owns the brand tokens (--cf-primary) and .brand-mark.
         landing.css is layered on top and only adds marketing-page rules. -->
    <link href="<?= e(url('assets/css/style.css')) ?>" rel="stylesheet">
    <link href="<?= e(url('assets/css/landing.css')) ?>" rel="stylesheet">
</head>
<body class="lp">

<a class="lp-skip" href="#main">Skip to content</a>

<!-- ============================== navbar ============================== -->
<header class="lp-nav">
    <div class="container d-flex align-items-center gap-3">
        <a class="lp-brand" href="<?= e(url()) ?>">
            <span class="brand-mark"><i class="bi bi-diagram-3-fill"></i></span>
            <span class="fw-semibold"><?= e(APP_SHORT) ?></span>
        </a>
        <nav class="lp-nav-links d-none d-md-flex ms-auto" aria-label="Sections">
            <a href="#about">About</a>
            <a href="#features">Features</a>
            <a href="#preview">Preview</a>
            <a href="#workflow">Workflow</a>
            <a href="#setup">Get started</a>
            <a href="#faq">FAQ</a>
        </nav>
        <div class="d-flex gap-2 ms-md-0 ms-auto">
            <?php if ($isSignedIn): ?>
                <a class="btn btn-primary btn-sm px-3" href="<?= e(url('dashboard.php')) ?>">
                    <i class="bi bi-speedometer2 me-1"></i>Open dashboard
                </a>
            <?php else: ?>
                <a class="btn btn-outline-secondary btn-sm px-3" href="<?= e(url('auth/login.php')) ?>">Sign in</a>
                <a class="btn btn-primary btn-sm px-3" href="<?= e(url('signup.php')) ?>">Create workspace</a>
                <a class="btn btn-outline-secondary btn-sm px-3 d-none d-sm-inline-block" href="#setup">Get started</a>
            <?php endif; ?>
        </div>
    </div>
</header>

<main id="main">

<!-- ============================== hero ============================== -->
<section class="lp-hero">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <span class="lp-pill">
                    <i class="bi bi-shield-lock-fill me-1"></i>Sign-in hardening and a recycle bin included
                </span>
                <h1 class="lp-h1">
                    A CRM that does the <span class="lp-grad">boring part properly</span>
                </h1>
                <p class="lp-lead">
                    Clients, leads, a Kanban pipeline, tasks and activity history in plain PHP and
                    MySQL. No framework, no build step, no third-party services - and the parts that
                    usually get skipped, like sign-in throttling and reversible deletes, are not
                    skipped.
                </p>
                <div class="d-flex flex-wrap gap-2 mb-4">
                    <?php if ($isSignedIn): ?>
                        <a class="btn btn-primary btn-lg px-4" href="<?= e(url('dashboard.php')) ?>">
                            <i class="bi bi-speedometer2 me-2"></i>Open the dashboard
                        </a>
                    <?php else: ?>
                        <a class="btn btn-primary btn-lg px-4" href="<?= e(url('signup.php')) ?>">
                            <i class="bi bi-building-add me-2"></i>Create a workspace
                        </a>
                        <a class="btn btn-outline-secondary btn-lg px-4" href="<?= e(url('auth/login.php')) ?>">
                            <i class="bi bi-box-arrow-in-right me-2"></i>Sign in
                        </a>
                        <a class="btn btn-outline-secondary btn-lg px-4" href="#setup">Get started</a>
                    <?php endif; ?>
                </div>
                <p class="lp-fine mb-0">
                    <?php if (DEMO_MODE): ?>
                        Demo accounts are shown on the sign-in page and force a password change.
                    <?php else: ?>
                        Sign-in is protected by rate limiting; accounts with a published password
                        must change it before they reach the app.
                    <?php endif; ?>
                </p>
            </div>

            <div class="col-lg-6">
                <?php if (is_file(__DIR__ . '/assets/img/dashboard.png')): ?>
                    <img src="<?= e(url('assets/img/dashboard.png')) ?>"
                         class="lp-shot lp-shot-hero"
                         alt="The ClientFlow dashboard: headline figures, a pipeline snapshot, tasks due today and recent activity."
                         width="1440" height="900" loading="eager" decoding="async">
                <?php else: ?>
                    <div class="lp-shot-placeholder" aria-hidden="true">
                        <i class="bi bi-window-sidebar"></i>
                        <span>Screenshot coming</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>

<!-- ============================== stats ============================== -->
<section class="lp-stats">
    <div class="container">
        <div class="row g-3">
            <?php foreach ([
                ['bi-code-slash',   'Plain PHP 8',  'No framework, no Composer'],
                ['bi-database',     'MySQL',        'Seven tables, foreign keys'],
                ['bi-wifi-off',     'Works offline','Assets vendored, zero CDN calls'],
                ['bi-shield-check', 'CSRF + CSP',   'Every form, every response'],
            ] as [$icon, $label, $hint]): ?>
                <div class="col-6 col-lg-3">
                    <div class="lp-stat">
                        <i class="bi <?= e($icon) ?>"></i>
                        <div>
                            <div class="lp-stat-label"><?= e($label) ?></div>
                            <div class="lp-stat-hint"><?= e($hint) ?></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ============================== about ============================== -->
<section id="about" class="lp-section">
    <div class="container">
        <div class="row g-5 align-items-center">
            <div class="col-lg-5">
                <h2 class="lp-h2">About this project</h2>
            </div>
            <div class="col-lg-7">
                <p class="lp-body">
                    Most small CRMs are either a hosted product you cannot inspect, or a codebase
                    where the interesting parts are the ones that were skipped. ClientFlow is an
                    attempt at the other end: small enough to read in an afternoon, complete enough
                    to actually keep records in.
                </p>
                <p class="lp-body">
                    Every screen follows the same three steps - validate, save, redirect - and every
                    database call lives in a model, so the pages stay presentation-only. Each
                    feature folder carries its own README explaining what is in it and which model
                    backs it.
                </p>
                <p class="lp-body mb-0">
                    It is also honest about its limits. The Integrations and FAQ sections below name
                    what has <em>not</em> been built, because a CRM that hides that is harder to
                    trust than one that admits it.
                </p>
            </div>
        </div>
    </div>
</section>

<!-- ============================== tabs ============================== -->
<section id="features" class="lp-section lp-section-alt">
    <div class="container">

        <div class="text-center mb-4">
            <h2 class="lp-h2">What it does</h2>
            <p class="lp-sub">
                Five views of the same app. The Preview tab is screenshots of this installation;
                nothing below is a mock-up.
            </p>
        </div>

        <ul class="nav nav-pills lp-tabs justify-content-center mb-4" role="tablist">
            <?php $tabs = [
                'features'     => ['bi-list-check',    'Features'],
                'preview'      => ['bi-camera',        'Live preview'],
                'workflow'     => ['bi-diagram-2',     'Workflow'],
                'integrations' => ['bi-plug',          'Integrations'],
                'faq'          => ['bi-question-circle','FAQ'],
            ]; ?>
            <?php $first = true; foreach ($tabs as $id => [$icon, $label]): ?>
                <li class="nav-item" role="presentation">
                    <button class="nav-link<?= $first ? ' active' : '' ?>"
                            id="tab-<?= e($id) ?>" data-bs-toggle="tab" data-bs-target="#panel-<?= e($id) ?>"
                            type="button" role="tab" aria-controls="panel-<?= e($id) ?>"
                            aria-selected="<?= $first ? 'true' : 'false' ?>">
                        <i class="bi <?= e($icon) ?> me-1"></i><?= e($label) ?>
                    </button>
                </li>
            <?php $first = false; endforeach; ?>
        </ul>

        <div class="tab-content lp-tab-content">

            <!-- ---------- Features ---------- -->
            <div class="tab-pane fade show active" id="panel-features" role="tabpanel" aria-labelledby="tab-features" tabindex="0">
                <div class="row g-3">
                    <?php foreach ($featureGroups as $group): ?>
                        <div class="col-md-6 col-lg-4">
                            <div class="lp-card h-100">
                                <h3 class="lp-card-title">
                                    <i class="bi <?= e($group['icon']) ?>"></i><?= e($group['title']) ?>
                                </h3>
                                <ul class="lp-list">
                                    <?php foreach ($group['items'] as $item): ?>
                                        <li><?= e($item) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ---------- Live preview ---------- -->
            <div class="tab-pane fade" id="panel-preview" role="tabpanel" aria-labelledby="tab-preview" tabindex="0">
                <p class="lp-fine text-center mb-4">
                    Captured from a running installation. The seeded data is fictional.
                </p>
                <div class="row g-3">
                    <?php foreach ($screenshots as $key => $shot): ?>
                        <div class="col-12 col-lg-6">
                            <figure class="lp-figure h-100">
                                <?php if (is_file(__DIR__ . '/assets/img/' . $shot['file'])): ?>
                                    <img src="<?= e(url('assets/img/' . $shot['file'])) ?>"
                                         class="lp-shot"
                                         alt="<?= e($shot['caption']) ?>"
                                         width="1440" height="900" loading="lazy" decoding="async">
                                <?php else: ?>
                                    <div class="lp-shot-placeholder" aria-hidden="true">
                                        <i class="bi bi-image"></i>
                                        <span><?= e($shot['file']) ?></span>
                                    </div>
                                <?php endif; ?>
                                <figcaption><?= e($shot['caption']) ?></figcaption>
                            </figure>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ---------- Workflow ---------- -->
            <div class="tab-pane fade" id="panel-workflow" role="tabpanel" aria-labelledby="tab-workflow" tabindex="0">
                <div class="row g-4">
                    <div class="col-lg-5">
                        <h3 class="lp-card-title"><i class="bi bi-funnel-fill"></i>One path, end to end</h3>
                        <p class="lp-body">
                            A prospect arrives as a lead. You work it along the statuses, convert it
                            into a deal when it is worth a forecast, and drag that deal across the
                            pipeline. When it closes, the account lives on as a client with the
                            whole history attached.
                        </p>
                        <p class="lp-body mb-0">
                            Nothing is retyped at any step, and the deal keeps pointing back at the
                            lead that produced it - so you can always see where the revenue came from.
                        </p>
                    </div>
                    <div class="col-lg-7">
                        <ol class="lp-steps">
                            <li>
                                <strong>Capture the lead</strong>
                                <span>Name, company, source and an owner. The source is an enum, so it reports cleanly later.</span>
                            </li>
                            <li>
                                <strong>Qualify it</strong>
                                <span>Work it along: <?= e(implode(' &rarr; ', array_slice($leadStatuses, 0, 3))) ?>, then
                                      <?= e(implode(' &rarr; ', array_slice($leadStatuses, 3, 2))) ?>.</span>
                            </li>
                            <li>
                                <strong>Convert to a deal</strong>
                                <span>One action creates the deal and advances the lead. It runs in a transaction, so a deal can never exist with its lead unadvanced.</span>
                            </li>
                            <li>
                                <strong>Move it along the pipeline</strong>
                                <span><?= e(implode(' &rarr; ', array_map(
                                    static fn (string $s): string => pretty(str_replace('_', ' ', $s)),
                                    $pipelineStages
                                ))) ?>.</span>
                            </li>
                            <li>
                                <strong>Log the follow-ups</strong>
                                <span>Tasks with a due date and an owner; calls, emails, meetings and notes recorded against the client or lead.</span>
                            </li>
                            <li>
                                <strong>Report on it</strong>
                                <span>Win rate by count and by value, a weighted forecast from the stage values, and team performance.</span>
                            </li>
                        </ol>
                    </div>
                </div>
            </div>

            <!-- ---------- Integrations ---------- -->
            <div class="tab-pane fade" id="panel-integrations" role="tabpanel" aria-labelledby="tab-integrations" tabindex="0">
                <div class="row g-3">
                    <div class="col-lg-6">
                        <div class="lp-card h-100">
                            <h3 class="lp-card-title"><i class="bi bi-box-arrow-in-down"></i>Runs on</h3>
                            <p class="lp-fine">What it genuinely needs today.</p>
                            <ul class="lp-list">
                                <?php foreach ($stackShips as $name => $note): ?>
                                    <li><strong><?= e($name) ?></strong> &mdash; <?= e($note) ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <p class="lp-fine mb-0 mt-3">
                                It also deliberately makes <em>no</em> external calls at runtime:
                                Bootstrap, the icons and the fonts are vendored into the project.
                            </p>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="lp-card h-100">
                            <h3 class="lp-card-title lp-card-title-muted">
                                <i class="bi bi-cone-striped"></i>Not built yet
                            </h3>
                            <p class="lp-fine">
                                Listed because a CRM that hides these is harder to trust than one
                                that names them.
                            </p>
                            <ul class="lp-list lp-list-muted">
                                <?php foreach ($stackPlanned as $name => $note): ?>
                                    <li><strong><?= e($name) ?></strong> &mdash; <?= e($note) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ---------- FAQ ---------- -->
            <div class="tab-pane fade" id="panel-faq" role="tabpanel" aria-labelledby="tab-faq" tabindex="0">
                <div class="accordion lp-accordion" id="faqAccordion">
                    <?php foreach ($faq as $i => $entry): ?>
                        <div class="accordion-item">
                            <h3 class="accordion-header" id="faqHead<?= $i ?>">
                                <button class="accordion-button<?= $i === 0 ? '' : ' collapsed' ?>"
                                        type="button" data-bs-toggle="collapse"
                                        data-bs-target="#faqBody<?= $i ?>"
                                        aria-expanded="<?= $i === 0 ? 'true' : 'false' ?>"
                                        aria-controls="faqBody<?= $i ?>">
                                    <?= e($entry['q']) ?>
                                </button>
                            </h3>
                            <div id="faqBody<?= $i ?>" class="accordion-collapse collapse<?= $i === 0 ? ' show' : '' ?>"
                                 aria-labelledby="faqHead<?= $i ?>" data-bs-parent="#faqAccordion">
                                <div class="accordion-body lp-body">
                                    <?= $entry['a'] /* deliberately pre-escaped per entry */ ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>
    </div>
</section>

<!-- ============================== setup ============================== -->
<section id="setup" class="lp-section">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="text-center mb-4">
                    <h2 class="lp-h2">Get started</h2>
                    <p class="lp-sub">
                        Four steps. Nothing to install beyond the stack it already needs.
                    </p>
                </div>

                <ol class="lp-setup">
                    <li>
                        <h3>Start Apache and MySQL</h3>
                        <p>Open the XAMPP control panel and start both. Any LAMP stack works.</p>
                    </li>
                    <li>
                        <h3>Copy the project into <code>htdocs</code></h3>
                        <p>Put the folder at <code>htdocs/clientflow</code>, or rename it - every
                           internal link is generated, so nothing breaks.</p>
                    </li>
                    <li>
                        <h3>Import the database</h3>
                        <p>phpMyAdmin &rarr; Import &rarr; <code>database.sql</code>. It creates the
                           schema and loads a small fictional dataset.</p>
                    </li>
                    <li>
                        <h3>Sign in</h3>
                        <p>Go to <a href="<?= e(url('auth/login.php')) ?>">the sign-in page</a>. On a
                           local install the demo accounts are listed there, and each one is required
                           to choose its own password before it reaches the app.</p>
                    </li>
                </ol>

                <div class="lp-callout mt-4">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <div>
                        <strong>Before you use this with real data.</strong> Take a backup first with
                        <code>tools/backup.ps1</code>. <code>database.sql</code> begins with
                        <code>DROP TABLE</code>, so re-importing it erases everything - and there is
                        no in-app export yet.
                    </div>
                </div>

                <div class="text-center mt-4">
                    <a class="btn btn-primary btn-lg px-4" href="<?= e(url('auth/login.php')) ?>">
                        <i class="bi bi-box-arrow-in-right me-2"></i>Sign in
                    </a>
                </div>
            </div>
        </div>
    </div>
</section>

</main>

<!-- ============================== footer ============================== -->
<footer class="lp-foot">
    <div class="container">
        <div class="row g-3 align-items-center">
            <div class="col-md-6">
                <div class="d-flex align-items-center gap-2">
                    <span class="brand-mark"><i class="bi bi-diagram-3-fill"></i></span>
                    <span class="fw-semibold"><?= e(APP_NAME) ?></span>
                </div>
                <p class="lp-fine mb-0 mt-2">
                    Plain PHP 8, MySQL and Bootstrap 5. No framework, no build step, no tracking.
                </p>
            </div>
            <div class="col-md-6 text-md-end">
                <p class="lp-fine mb-2">
                    Provided as-is for learning and portfolio use. Bootstrap and Bootstrap Icons are
                    MIT licensed.
                </p>
                <p class="lp-fine mb-0">
                    <?php if ($isSignedIn): ?>
                        <a href="<?= e(url('dashboard.php')) ?>">Open dashboard</a>
                        &nbsp;&middot;&nbsp;
                        <a href="<?= e(url('index.php')) ?>">Home</a>
                    <?php else: ?>
                        <a href="<?= e(url('auth/login.php')) ?>">Sign in</a>
                        &nbsp;&middot;&nbsp;
                        <a href="<?= e(url('signup.php')) ?>">Create a workspace</a>
                        &nbsp;&middot;&nbsp;
                        <a href="<?= e(url('index.php')) ?>">Home</a>
                    <?php endif; ?>
                    &nbsp;&middot;&nbsp;
                    <a href="#faq">FAQ</a>
                </p>
            </div>
        </div>
    </div>
</footer>

<script src="<?= e(url('assets/vendor/js/bootstrap.bundle.min.js')) ?>"></script>
</body>
</html>