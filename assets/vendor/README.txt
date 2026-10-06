Third-party assets vendored here so the app runs fully offline.

--------------------------------------------------------------------------------
Bootstrap v5.3.3
--------------------------------------------------------------------------------
Files
    css/bootstrap.min.css
    css/bootstrap.min.css.map
    js/bootstrap.bundle.min.js
    js/bootstrap.bundle.min.js.map

Source  https://github.com/twbs/bootstrap/releases/tag/v5.3.3
Licence MIT - https://github.com/twbs/bootstrap/blob/main/LICENSE

--------------------------------------------------------------------------------
Bootstrap Icons v1.11.3
--------------------------------------------------------------------------------
Files
    css/bootstrap-icons.min.css
    fonts/bootstrap-icons.woff
    fonts/bootstrap-icons.woff2

Source  https://github.com/twbs/icons/releases/tag/v1.11.3
Licence MIT - https://github.com/twbs/icons/blob/main/LICENSE.md

--------------------------------------------------------------------------------
Local modification
--------------------------------------------------------------------------------
One change was made to bootstrap-icons.min.css after downloading: the font
paths were rewritten from

    url("fonts/bootstrap-icons.woff2")

to

    url("../fonts/bootstrap-icons.woff2")

because the font files are kept in ../fonts/ rather than alongside the
stylesheet. No other content was altered.
