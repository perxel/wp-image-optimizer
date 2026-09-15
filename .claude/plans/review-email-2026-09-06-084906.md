# Plugin Check Report

**Plugin:** Perxel Image Optimizer
**Generated at:** 2026-09-03 08:49:06


## `readme.txt`

| Line | Column | Type | Code | Message | Docs |
| --- | --- | --- | --- | --- | --- |
| 0 | 0 | ERROR | stable_tag_mismatch | Mismatched Stable Tag: 1.0.0 != 1.1.0. Your Stable Tag is meant to be the stable version of your plugin and it needs to be exactly the same with the Version in your main plugin file's header. Any mismatch can prevent users from downloading the correct plugin files from WordPress.org. | [Docs](https://developer.wordpress.org/plugins/wordpress-org/common-issues/#incorrect-stable-tag) |

## `perxel-image-optimizer.php`

| Line | Column | Type | Code | Message | Docs |
| --- | --- | --- | --- | --- | --- |
| 0 | 0 | WARNING | plugin_header_nonexistent_domain_path | The "Domain Path" header in the plugin file must point to an existing folder. Found: "languages" | [Docs](https://developer.wordpress.org/plugins/internationalization/how-to-internationalize-your-plugin/#domain-path) |

## `includes/Runner.php`

| Line | Column | Type | Code | Message | Docs |
| --- | --- | --- | --- | --- | --- |
| 448 | 5 | WARNING | Squiz.PHP.DiscouragedFunctions.Discouraged | The use of function set_time_limit() is discouraged |  |
| 608 | 5 | WARNING | Squiz.PHP.DiscouragedFunctions.Discouraged | The use of function set_time_limit() is discouraged |  |

## `includes/Catchup.php`

| Line | Column | Type | Code | Message | Docs |
| --- | --- | --- | --- | --- | --- |
| 85 | 5 | WARNING | Squiz.PHP.DiscouragedFunctions.Discouraged | The use of function set_time_limit() is discouraged |  |

## `includes/Plugin.php`

| Line | Column | Type | Code | Message | Docs |
| --- | --- | --- | --- | --- | --- |
| 48 | 3 | WARNING | PluginCheck.CodeAnalysis.DiscouragedFunctions.load_plugin_textdomainFound | load_plugin_textdomain() has been discouraged since WordPress version 4.6. When your plugin is hosted on WordPress.org, you no longer need to manually include this function call for translations under your plugin slug. WordPress will automatically load the translations for you as needed. | [Docs](https://make.wordpress.org/core/2016/07/06/i18n-improvements-in-4-6/) |

## `ui/partials/footer.php`

| Line | Column | Type | Code | Message | Docs |
| --- | --- | --- | --- | --- | --- |
| 14 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$author&quot;. |  |
| 15 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$author_name&quot;. |  |
| 16 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$author_url&quot;. |  |
| 17 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$version&quot;. |  |
| 36 | 52 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$label&quot;. |  |
| 36 | 62 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$url&quot;. |  |

## `ui/partials/main-bar.php`

| Line | Column | Type | Code | Message | Docs |
| --- | --- | --- | --- | --- | --- |
| 14 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$has_title&quot;. |  |
| 15 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$has_actions&quot;. |  |

## `ui/partials/sidebar.php`

| Line | Column | Type | Code | Message | Docs |
| --- | --- | --- | --- | --- | --- |
| 19 | 47 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$group&quot;. |  |
| 19 | 57 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$items&quot;. |  |
| 24 | 51 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$slug&quot;. |  |
| 24 | 60 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$label&quot;. |  |
| 25 | 27 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$is_active&quot;. |  |

## `uninstall.php`

| Line | Column | Type | Code | Message | Docs |
| --- | --- | --- | --- | --- | --- |
| 19 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$options&quot;. |  |
| 32 | 23 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$option&quot;. |  |
| 72 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$htaccess&quot;. |  |

## `includes/views/status.php`

| Line | Column | Type | Code | Message | Docs |
| --- | --- | --- | --- | --- | --- |
| 18 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$job&quot;. |  |
| 19 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$scan&quot;. |  |
| 20 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$stats&quot;. |  |
| 21 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$env&quot;. |  |
| 22 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$settings_url&quot;. |  |
| 23 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$free_disk&quot;. |  |
| 30 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$glance&quot;. |  |
| 78 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$figure_group&quot;. |  |
| 128 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$enable_serve_form&quot;. |  |
| 162 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$cfg&quot;. |  |
| 163 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$skip_converted&quot;. |  |
| 164 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$serve_on&quot;. |  |
| 165 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$total&quot;. |  |
| 166 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$est_all&quot;. |  |
| 170 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$mp_ceiling&quot;. |  |
| 172 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$mp_ceiling&quot;. |  |
| 210 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$eta&quot;. |  |
| 211 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$eta_text&quot;. |  |
| 215 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$pace_sub&quot;. |  |
| 219 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$pace_sub&quot;. |  |
| 222 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$run_rows&quot;. |  |
| 232 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$run_rows&quot;. |  |
| 240 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$run_rows&quot;. |  |
| 247 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$run_note&quot;. |  |
| 248 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$report_to&quot;. |  |
| 250 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$run_note&quot;. |  |
| 263 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$by_year&quot;. |  |
| 264 | 40 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$section&quot;. |  |
| 265 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$ym&quot;. |  |
| 266 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$count&quot;. |  |
| 272 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$by_year&quot;. |  |
| 279 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$per_image&quot;. |  |
| 281 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$per_image_fast&quot;. |  |
| 283 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$per_image_fast&quot;. |  |
| 294 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$driver_rows&quot;. |  |
| 316 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$scope_rows&quot;. |  |
| 345 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$year_rows&quot;. |  |
| 347 | 27 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$group_year&quot;. |  |
| 347 | 42 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$group_months&quot;. |  |
| 348 | 9 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$all_label&quot;. |  |
| 350 | 9 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$month_list&quot;. |  |
| 351 | 9 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$month_list&quot;. |  |
| 357 | 9 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$y_count&quot;. |  |
| 359 | 36 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$gm&quot;. |  |
| 360 | 13 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$y_count&quot;. |  |
| 362 | 13 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$mm&quot;. |  |
| 364 | 13 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$sub&quot;. |  |
| 366 | 13 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$month_list&quot;. |  |
| 376 | 9 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$month_list&quot;. |  |
| 379 | 9 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$y_total_text&quot;. |  |
| 381 | 9 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$year_rows&quot;. |  |
| 409 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$sizes_cfg&quot;. |  |
| 410 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$sizes_txt&quot;. |  |

## `includes/views/settings.php`

| Line | Column | Type | Code | Message | Docs |
| --- | --- | --- | --- | --- | --- |
| 17 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$env&quot;. |  |
| 18 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$cfg&quot;. |  |
| 19 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$srv&quot;. |  |
| 20 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$sizes&quot;. |  |
| 22 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$engine&quot;. |  |
| 30 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$quality_steps&quot;. |  |
| 36 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$quality_select&quot;. |  |
| 64 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$mail_err_key&quot;. |  |
| 65 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$mail_err&quot;. |  |
| 75 | 9 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$msg&quot;. |  |
| 77 | 13 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$msg&quot;. |  |
| 85 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$subsizes&quot;. |  |
| 91 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$size_dimensions&quot;. |  |
| 108 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$size_options&quot;. |  |
| 109 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$size_selected&quot;. |  |
| 110 | 37 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$name&quot;. |  |
| 111 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$size_options&quot;. |  |
| 117 | 9 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$size_selected&quot;. |  |
| 206 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$serve_sub&quot;. |  |
| 240 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$email_to&quot;. |  |
| 241 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$report_on&quot;. |  |
| 242 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$missing_addr&quot;. |  |
| 244 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$notif_note&quot;. |  |
| 248 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$notif_rows&quot;. |  |
| 271 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$notif_rows&quot;. |  |
| 337 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$env_ok&quot;. |  |
| 339 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$env_detail&quot;. |  |
| 357 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$env_pad&quot;. |  |
| 358 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$env_lines&quot;. |  |
| 359 | 26 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$env_key&quot;. |  |
| 359 | 38 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$env_value&quot;. |  |
| 360 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$env_lines&quot;. |  |
| 364 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$serve_row&quot;. |  |
| 371 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$serve_row&quot;. |  |
| 377 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$serve_row&quot;. |  |

## `includes/views/status-monitor.php`

| Line | Column | Type | Code | Message | Docs |
| --- | --- | --- | --- | --- | --- |
| 25 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$job&quot;. |  |
| 26 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$failures&quot;. |  |
| 27 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$driver&quot;. |  |
| 28 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$preason&quot;. |  |
| 29 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$processed&quot;. |  |
| 30 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$total&quot;. |  |
| 31 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$saved&quot;. |  |
| 32 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$cancelled&quot;. |  |
| 33 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$is_done&quot;. |  |
| 34 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$elapsed&quot;. |  |
| 35 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$log_url&quot;. |  |
| 43 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$row_label&quot;. |  |
| 47 | 9 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$phase_title&quot;. |  |
| 48 | 9 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$row_label&quot;. |  |
| 49 | 9 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$row_icon&quot;. |  |
| 50 | 9 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$sentence&quot;. |  |
| 56 | 9 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$phase_title&quot;. |  |
| 57 | 9 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$row_icon&quot;. |  |
| 58 | 9 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$sentence&quot;. |  |
| 62 | 9 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$phase_title&quot;. |  |
| 63 | 9 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$row_icon&quot;. |  |
| 65 | 13 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$sentence&quot;. |  |
| 67 | 13 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$sentence&quot;. |  |
| 69 | 13 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$sentence&quot;. |  |
| 71 | 13 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$sentence&quot;. |  |
| 76 | 9 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$phase_title&quot;. |  |
| 77 | 9 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$row_label&quot;. |  |
| 78 | 9 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$row_icon&quot;. |  |
| 79 | 9 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$sentence&quot;. |  |
| 96 | 13 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$sentence&quot;. |  |
| 101 | 9 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$phase_title&quot;. |  |
| 102 | 9 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$row_icon&quot;. |  |
| 103 | 9 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$sentence&quot;. |  |
| 114 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$live_run&quot;. |  |
| 117 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$eta_txt&quot;. |  |
| 120 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$row1_sub&quot;. |  |
| 125 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$row1_sub&quot;. |  |
| 128 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$rows&quot;. |  |
| 146 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$rows&quot;. |  |
| 151 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$rows&quot;. |  |
| 157 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$rows&quot;. |  |
| 164 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$eta_txt&quot;. |  |
| 168 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$rows&quot;. |  |
| 181 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$intensity_now&quot;. |  |
| 182 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$intensity_opts&quot;. |  |
| 187 | 10 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$val&quot;. |  |
| 187 | 18 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$opt_label&quot;. |  |
| 188 | 9 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$intensity_opts&quot;. |  |
| 192 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$rows&quot;. |  |
| 202 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$list&quot;. |  |
| 203 | 28 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$row&quot;. |  |
| 204 | 9 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$list&quot;. |  |
| 213 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$rows&quot;. |  |
| 234 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$note_lines&quot;. |  |
| 237 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$note_lines&quot;. |  |
| 242 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$meta_parts&quot;. |  |
| 244 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$since&quot;. |  |
| 246 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$rel&quot;. |  |
| 251 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$meta_parts&quot;. |  |
| 259 | 5 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$meta_parts&quot;. |  |
| 261 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$note_lines&quot;. |  |
| 267 | 1 | WARNING | WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound | Global variables defined by a theme/plugin should start with the theme/plugin prefix. Found: &quot;$poll&quot;. |  |
