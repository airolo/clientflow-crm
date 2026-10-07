# views

The presentation shell, shared by every page. These files emit HTML and know
nothing about the database.

| File | Responsibility |
|---|---|
| `header.php` | `<head>`, asset tags, page heading, breadcrumbs; opens `<main>`. Reads `$pageTitle`, `$pageHeading`, `$pageSubtitle`, `$activeNav`, `$breadcrumbs`, `$pageActions`, `$reopenModal` |
| `footer.php` | Closes `<main>`, the footer, the shared confirmation dialog, and the script tags |
| `navbar.php` | Top bar with the user dropdown. Included twice — desktop and mobile |
| `sidebar.php` | Navigation links. Included twice, which is why the active-link logic lives in a variable rather than being hard-coded |
| `alerts.php` | Flash messages |

## The page variables

A page sets these before including `header.php`:

```php
$pageTitle    = 'Clients';                       // <title> and breadcrumb
$pageHeading  = 'Clients';                       // the H1
$pageSubtitle = '12 accounts on file';           // optional line under the H1
$activeNav    = 'clients';                       // highlights the sidebar link
$breadcrumbs  = ['Dashboard' => 'dashboard.php', 'Clients' => null];
$pageActions  = [['label' => 'Add client', 'href' => 'clients/form.php', 'icon' => 'bi-plus-lg']];
$reopenModal  = '';                              // reopens a dialog after a failed submit
```

`$pageActions` is an array, not HTML. It is rendered by
`render_page_actions()` in `app/list_page.php`, which escapes the label and
restricts the `href` — that replaced an unescaped echo that was safe only because
every call site happened to use a static string.

## Why nothing here reads data

The sidebar needs the signed-in user's open task count for its badge. That used to
be fetched by all 17 pages individually and passed in as `$sidebarPendingTasks`;
it is now computed once in `header.php`.
