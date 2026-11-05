# Templates

## Table of contents

- [Twig namespace](#twig-namespace)
- [Account link card](#account-link-card)
  - [Including it](#including-it)
  - [What it renders](#what-it-renders)
  - [Overriding it](#overriding-it)
- [Settings section](#settings-section)
- [Slack-side texts](#slack-side-texts)

## Twig namespace

The bundle registers `@NowoSlackKitBundle` (REQ-TWIG-002) for `src/Resources/views`. Every template can be overridden
under `templates/bundles/NowoSlackKitBundle/` (REQ-TWIG-001).

## Account link card

### Including it

```twig
{# templates/profile/show.html.twig #}
{{ include('@NowoSlackKitBundle/account/_link.html.twig') }}
```

It renders nothing when linking is disabled or `SlackAccountResolverInterface::currentAccountId()` returns `null`.

### What it renders

A UiKit card (`@NowoUiKitBundle/partials/_card.html.twig`, id `slack-link`) with:

- the status line (`data-testid="slack-kit-link-status"`, `data-status` = `none` | `claimed` | `verified`);
- while not verified, the member-id form (`SlackUserIdType`, `data-testid="slack-kit-link-claim"`), rendered with
  `form_start` / child loop / `form_end` (REQ-TWIG-003, REQ-TWIG-005);
- the actions as UiKit POST actions with CSRF-only forms: *Find me by e-mail* (when allowed and not verified),
  *Send confirmation* (when claimed), *Unlink* (with a confirm dialog, when linked). Their `data-testid` is the route
  name (`nowo_slack_kit_link_find`, `…_confirm`, `…_unlink`).

The data comes from `nowo_slack_kit_link_card()`:

| Key | Type |
| --- | --- |
| `status` | `none` \| `claimed` \| `verified` |
| `slack_user_id` | `?string` |
| `claim_form` | `?FormView` (null once verified) |
| `actions` | list of UiKit `Action` |

Classes come from `nowo_ui_class(…, 'NowoSlackKitBundle')`, so UiKit themes apply; there is no bundle CSS.

### Overriding it

Copy the template to `templates/bundles/NowoSlackKitBundle/account/_link.html.twig` and keep:

- every action as a POST with its CSRF form (use `form_start(action.form)` / `form_end`, never a raw `<form>`);
- the member-id form rendered through its `FormView`;
- the status attributes if your e2e tests rely on them.

Or build your own markup from `nowo_slack_kit_link_card()` in any template.

## Settings section

The **Slack** section is rendered by InstanceSettings' own templates (fields, notices, actions). Customise it through
InstanceSettings template overrides; the bundle only provides the section definition (`SlackSettingsSection`).

## Slack-side texts

Messages sent to Slack (confirmation DM, *Linked*, *Confirmation refused*, *Link and confirm…*, *This button is no
longer valid*, the generic error) are not templates: they are translation keys of the `NowoSlackKitBundle` domain
(`slack.confirm.*`, `interaction.*`), rendered in the recipient's locale (identity store) or `default_locale`.
Override them in `translations/NowoSlackKitBundle.<locale>.yaml` of your app.
