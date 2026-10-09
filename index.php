<?php
/**
 * Public landing page.
 *
 * This is index.php, so Apache serves it for the site root. It is the only
 * marketing page in the project and it is deliberately NOT behind
 * require_login(): a visitor who has never heard of ClientFlow should see what
 * it is before being asked to log in.
 *
 * Audience: someone deciding whether to put their customer list into this. The
 * copy is therefore written for a business owner rather than for a developer -
 * what you get, and what happens to your data - rather than which columns the
 * schema has. The implementation still gets described, but further down and
 * because it earns its place, not as the headline.
 *
 * Two rules keep it honest, and warmth is not a licence to drop either:
 *   1. It renders no business data. Everything here is static markup, so the
 *      page cannot leak a customer's details by being indexed or cached.
 *   2. It claims only what the app actually does. Where something is missing it
 *      says so plainly - see the Integrations and FAQ tabs. A landing page that
 *      oversells is the fastest way to lose the trust it was trying to win, so
 *      the awkward answers stay even where a warmer wording was tempting.
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

/** One line per feature, grouped so the tab renders a consistent card grid.
 *
 *  Written as what you get rather than which columns exist. "Clients with
 *  company, named contact, email, phone, address and notes" describes a schema;
 *  "the contact details you actually need, in one record" describes a reason to
 *  use it. Same features, second one is aimed at the person reading.
 */
$featureGroups = [
    [
        'title' => 'Customers and pipeline',
        'icon'  => 'bi-people-fill',
        'items' => [
            'Every customer in one record, with their contact details and notes',
            'Leads tracked from first contact through to won or lost',
            'A Kanban board of live deals, with the value in each stage',
            'Turn a won lead into a deal without retyping a thing',
        ],
    ],
    [
        'title' => 'Follow-ups',
        'icon'  => 'bi-check2-square',
        'items' => [
            'Tasks with a due date, so nothing you promised gets forgotten',
            'One click to mark something done, and it records when',
            'A single list of what is overdue and what is due today',
            'Calls, emails, meetings and notes logged against the customer',
        ],
    ],
    [
        'title' => 'Finding things',
        'icon'  => 'bi-search',
        'items' => [
            'Search by company, contact name, email or phone',
            'Filter down to the records you actually care about today',
            'Sort by any column, so the list reads the way you work',
            'Clean paged lists instead of an endless scroll',
        ],
    ],
    [
        'title' => 'Knowing where you stand',
        'icon'  => 'bi-bar-chart-fill',
        'items' => [
            'Win rate by deal count and by value',
            'A forecast weighted by how far each deal has actually got',
            'Growth over time, and how each person is performing',
            'Which sources actually produce customers, not just leads',
        ],
    ],
    [
        'title' => 'Working as a team',
        'icon'  => 'bi-person-badge-fill',
        'items' => [
            'Admin and staff roles, so nobody edits what they should not',
            'Staff see their own accounts; the shared view stays shared',
            'Permissions checked on the server, not just hidden in the interface',
            'No buttons offered that would be refused if you pressed them',
        ],
    ],
    [
        'title' => 'Nothing gets lost',
        'icon'  => 'bi-shield-lock-fill',
        'items' => [
            'Deleting is reversible - records go to a recycle bin first',
            'Restoring a customer brings its deals and tasks back too',
            'A permanent record of who changed what, and what it was before',
            'A backup script, so a bad import is never the end of the story',
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

/** FAQ. The "not yet" answers are the point - see the note above.
 *
 *  Kept blunt where the answer is a limitation. Softening "no" into "coming soon"
 *  on a feature that does not exist is how a page loses the reader's trust, and
 *  these are the questions someone will ask before they commit a customer list.
 */
$faq = [
    [
        'q' => 'Do I have to host this somewhere myself?',
        'a' => 'ClientFlow is software you run yourself, not a service we sell you &mdash; there is
                no subscription and no vendor behind it. On a running instance, though, creating a
                workspace takes about a minute: you become its first admin and you are signed in
                straight away. Running it, and backing it up, is your responsibility.',
    ],
    [
        'q' => 'Can I get my data out?',
        'a' => 'Yes, and you should be able to leave whenever you want. Every list screen has an
                export button, for clients, leads, deals, tasks and activities, and there is a bundle
                that gathers your whole workspace into one download. The file contains only your own
                workspace. There is also <code>tools/backup.ps1</code>, which takes a full copy of
                the database to a timestamped file.',
    ],
    [
        'q' => 'Can I bring my data in from a spreadsheet?',
        'a' => 'Yes. Upload a CSV and you get a review screen first: how many records will be
                created, which look like ones you already have, and exactly what is wrong with any
                row that failed. Nothing is saved until you confirm. Column headings are matched
                loosely, so <code>Company Name</code> and <code>company_name</code> both work, and a
                file you export from here comes straight back in.',
    ],
    [
        'q' => 'Does it send email or connect to my inbox?',
        'a' => 'No. You can record a call, an email, a meeting or a note against a customer, and it
                is all there next time you look - but nothing is sent from the app and it does not
                read your mailbox. Sending email and inbox sync are both unbuilt.',
    ],
    [
        'q' => 'What happens if I delete a customer by mistake?',
        'a' => 'They move to a recycle bin rather than disappearing, and their deals, tasks and
                history stay intact and hidden. An administrator can restore them and everything
                attached comes straight back. Only "Delete forever" is irreversible, and it makes you
                type the record\'s name first.',
    ],
    [
        'q' => 'Can more than one person work in it?',
        'a' => 'Yes, with two roles. Admins can see and edit everything; staff are limited to
                records they created or are assigned to. That applies to editing and to opening a
                record at all. Lists and reports stay shared across the team, so you are not all
                working from different versions of the truth.',
    ],
    [
        'q' => 'Can I see who changed a record?',
        'a' => 'Yes. There is an audit log, and it records values rather than just events. Every
                create, edit, delete, restore and sign-in is recorded with who did it and when, and
                an edit shows what each field was before as well as after - not just "someone edited
                this record". Entries are kept permanently and cannot be changed or removed from the
                app.',
    ],
    [
        'q' => 'Does it need an internet connection?',
        'a' => 'No. The interface, the icons and the fonts all ship inside the project, so it makes
                no third-party requests at any point - not even on an error page.',
    ],
    [
        'q' => 'What does it need to run?',
        'a' => 'Any ordinary LAMP host: PHP 8, MySQL 5.7 or newer, and Apache. On a local machine
                that usually means XAMPP. Import <code>database.sql</code>, create an account, and
                you are in. There is nothing to install and no build step.',
    ],
    [
        'q' => 'Can I track several people at one company?',
        'a' => 'Not yet, and it is a real limitation rather than a wording issue. A customer record
                holds one named contact, with their email and phone. Tracking several people at one
                company currently means separate customer records, which is not how a sales team
                actually works. This is the largest gap in the app.',
    ],
    [
        'q' => 'Can I rely on this being maintained?',
        'a' => 'Not in the way you would rely on a paid product. There is no company behind it, no
                support desk and no service level agreement, and it is not sold. It is a complete,
                working CRM you can read and change yourself - which is the point of it - but if you
                need someone to call, this is not that.',
    ],
];

$pageTitle = 'ClientFlow CRM - every customer, lead and deal in one place';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <meta name="description" content="ClientFlow keeps your customers, leads, pipeline and follow-ups in one place, so you always know which deal is live and what needs chasing. Reversible deletes, a real audit trail, and you run it on your own server.">
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
            <a href="#about">Overview</a>
            <!--
                href="#features" on the tab links below is the no-JavaScript
                fallback: it lands on the right section, just on the default tab.
                assets/js/landing.js reads data-tab-target to open the tab itself.
                It cannot be done in markup because these are <a> elements and
                the panels are driven by Bootstrap's tab script.
            -->
            <a href="#features" data-tab-target="features">Features</a>
            <a href="#features" data-tab-target="preview">Preview</a>
            <a href="#features" data-tab-target="workflow">How it works</a>
            <a href="#features" data-tab-target="faq">FAQ</a>
        </nav>
        <div class="d-flex gap-2 ms-md-0 ms-auto">
            <?php if ($isSignedIn): ?>
                <a class="btn btn-primary btn-sm px-3" href="<?= e(url('dashboard.php')) ?>">
                    <i class="bi bi-speedometer2 me-1"></i>Open dashboard
                </a>
            <?php else: ?>
                <a class="btn btn-outline-secondary btn-sm px-3" href="<?= e(url('auth/login.php')) ?>">Sign in</a>
                <!--
                    Get Started, not "Create workspace". The section it points at
                    is what explains how to get going, and it ends with the
                    signup button - so this leads there rather than skipping
                    past the explanation to the form.
                -->
                <a class="btn btn-primary btn-sm px-3" href="#setup">Get Started</a>
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
                    <i class="bi bi-shield-lock-fill me-1"></i>Your data stays on your own server
                </span>
                <h1 class="lp-h1">
                    Know exactly where <span class="lp-grad">every deal stands</span>
                </h1>
                <p class="lp-lead">
                    Keep every customer, lead and follow-up in one place, so you can see at a glance
                    what is live, what is stuck and what needs chasing today. Run it on your own
                    server, and it keeps a permanent record of who changed what and when - including
                    every edit, so nothing gets quietly overwritten.
                </p>
                <div class="d-flex flex-wrap gap-2 mb-4">
                    <?php if ($isSignedIn): ?>
                        <a class="btn btn-primary btn-lg px-4" href="<?= e(url('dashboard.php')) ?>">
                            <i class="bi bi-speedometer2 me-2"></i>Open the dashboard
                        </a>
                    <?php else: ?>
                        <a class="btn btn-primary btn-lg px-4" href="#setup">
                            <i class="bi bi-rocket-takeoff me-2"></i>Get Started
                        </a>
                        <a class="btn btn-outline-secondary btn-lg px-4" href="<?= e(url('auth/login.php')) ?>">
                            <i class="bi bi-box-arrow-in-right me-2"></i>Sign in
                        </a>
                    <?php endif; ?>
                </div>
                <p class="lp-fine mb-0">
                    <?php if (DEMO_MODE): ?>
                        You can look around first: demo accounts are shown on the sign-in page, and
                        each one asks you to pick its own password before it gets in.
                    <?php else: ?>
                        Sign-in is protected by rate limiting, and any account still using a
                        published password has to change it before it reaches your data.
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
                ['bi-shield-lock',  'Yours to host',   'Runs on your server, no third parties'],
                ['bi-clock-history','Full history',    'Every call, email and note kept'],
                ['bi-trash3',       'Nothing is lost', 'Deletes are reversible'],
                ['bi-people',       'Built for a team','Admin and staff, separate access'],
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
                <h2 class="lp-h2">What this is for</h2>
            </div>
            <div class="col-lg-7">
                <p class="lp-body">
                    Most small-business software is a trade-off: either it is a service you rent and
                    trust with your customer list, or it is a codebase you are handed and have to
                    understand before you can rely on it. ClientFlow is the second kind, and it is
                    built to be the version you can actually read.
                </p>
                <p class="lp-body">
                    It does the everyday work of running a customer list - who you deal with, what
                    stage each deal is at, what you promised to follow up on - and it keeps the
                    record properly while it does it. Nothing is retyped between steps, and a deal
                    keeps pointing back to the lead that produced it, so you can always see where
                    the revenue came from.
                </p>
                <p class="lp-body mb-0">
                    It is also honest about where it stops. The Integrations section and the FAQ
                    below name what has <em>not</em> been built, because software that hides its
                    limits is harder to trust than software that tells you where they are.
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
                            <h3 class="lp-card-title"><i class="bi bi-box-arrow-in-down"></i>What it runs on</h3>
                            <p class="lp-fine">Plain, ordinary hosting. Nothing exotic to set up.</p>
                            <ul class="lp-list">
                                <?php foreach ($stackShips as $name => $note): ?>
                                    <li><strong><?= e($name) ?></strong> &mdash; <?= e($note) ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <p class="lp-fine mb-0 mt-3">
                                It also makes <em>no</em> external calls while you use it: the
                                interface, the icons and the fonts all ship inside the project, so
                                nothing about your business is sent to anyone else's server.
                            </p>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="lp-card h-100">
                            <h3 class="lp-card-title lp-card-title-muted">
                                <i class="bi bi-cone-striped"></i>Not built yet
                            </h3>
                            <p class="lp-fine">
                                Listed here on purpose. You should be able to plan around a gap
                                rather than discover it halfway through a quarter.
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
                    <h2 class="lp-h2">Get Started</h2>
                    <p class="lp-sub">
                        Four steps from an empty workspace to one you are actually working in.
                    </p>
                </div>

                <ol class="lp-setup">
                    <li>
                        <h3>Create your workspace</h3>
                        <p>Give your business a name and pick an email and password. The workspace
                           address is worked out from the business name, or set it yourself if you
                           would rather. You become its first admin.</p>
                    </li>
                    <li>
                        <h3>Add your first client</h3>
                        <p>You land on a short welcome that walks you through it. Already have a
                           spreadsheet? Upload a CSV instead &mdash; you get a review screen first,
                           showing what would be created and what looks like a duplicate, and nothing
                           saves until you say so.</p>
                    </li>
                    <li>
                        <h3>Set your currency and timezone</h3>
                        <p>One setting each, and money and dates then read the way you work rather
                           than the server's idea of them.</p>
                    </li>
                    <li>
                        <h3>Add your team</h3>
                        <p>Admins see and edit everything. Staff are limited to the records they
                           created or are assigned to, and your shared lists and reports stay
                           shared.</p>
                    </li>
                </ol>

        

                <p class="lp-fine text-center mt-4 mb-3">
                    Nothing is charged, and nothing is emailed to you &mdash; you are signed in
                    straight away.
                </p>

                <div class="text-center mt-4">
                    <a class="btn btn-primary btn-lg px-4" href="<?= e(url('signup.php')) ?>">
                        <i class="bi bi-building-add me-2"></i>Create a workspace
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
                    Runs on your own server. No tracking, no external calls, nothing about your
                    business sent anywhere else.
                </p>
            </div>
            <div class="col-md-6 text-md-end">
                <p class="lp-fine mb-2">
                    Not a commercial product: no company behind it, no support desk, and no
                    service level agreement. Bootstrap and Bootstrap Icons are MIT licensed.
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
                    <a href="#features" data-tab-target="faq">FAQ</a>
                </p>
            </div>
        </div>
    </div>
</footer>

<script src="<?= e(url('assets/vendor/js/bootstrap.bundle.min.js')) ?>"></script>
<!--
    Loaded after the Bootstrap bundle because landing.js calls the Tab API.
    External rather than inline because the CSP is script-src 'self'; see the
    note above the nav links.
-->
<script src="<?= e(url('assets/js/landing.js')) ?>"></script>
</body>
</html>