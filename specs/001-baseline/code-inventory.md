# Code inventory — 001-baseline

**Last audited**: 2026-11-27 (sweep: 2026-11-05 baseline + later sources; `IncomingWebhookClientInterface`, 1.7.0)
**Scope**: every production file under `src/` (136 files, `find src -type f | wc -l`). Tests, demo and generated caches are out of scope.

## Bundle / DI

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/DependencyInjection/Compiler/ActionHandlerPass.php` | Bundle / DI | FR-DI-002 |
| `src/DependencyInjection/Compiler/CsrfPass.php` | Bundle / DI | FR-DI-002 |
| `src/DependencyInjection/Compiler/ReplayLockPass.php` | Bundle / DI | FR-DI-002, FR-ING-001 |
| `src/DependencyInjection/Configuration.php` | Bundle / DI | FR-DI-002 |
| `src/DependencyInjection/NowoSlackKitExtension.php` | Bundle / DI | FR-DI-002 |
| `src/NowoSlackKitBundle.php` | Bundle / DI | FR-DI-001 |
| `src/Routing/SlackKitRouteLoader.php` | Bundle / DI | FR-DI-003 |

## Web API client

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Api/ApiPolicy.php` | Web API client | FR-API-001 |
| `src/Api/AuthTestResult.php` | Web API client | FR-API-001 |
| `src/Api/ClientMemory.php` | Web API client | FR-API-001 |
| `src/Api/EmailLookupDisabledException.php` | Web API client | FR-API-001 |
| `src/Api/IncomingWebhookClient.php` | Web API client | FR-API-001 |
| `src/Api/IncomingWebhookClientInterface.php` | Web API client: port of the incoming-webhook poster (`https://hooks.slack.com/services/…` only); hosts type-hint it and substitute a test double (REQ-ARCH-001, 1.7.0) | FR-API-001, FR-API-004 |
| `src/Api/PostedMessage.php` | Web API client | FR-API-001 |
| `src/Api/RecoverableSlackException.php` | Web API client | FR-API-001 |
| `src/Api/RedactedTransportException.php` | Web API client | FR-API-001 |
| `src/Api/SlackApiClient.php` | Web API client | FR-API-001 |
| `src/Api/SlackApiClientInterface.php` | Web API client | FR-API-001 |
| `src/Api/SlackApiException.php` | Web API client | FR-API-001 |
| `src/Api/SlackAuthException.php` | Web API client | FR-API-001 |
| `src/Api/SlackMessage.php` | Web API client | FR-API-001 |
| `src/Api/SlackRateLimitedException.php` | Web API client | FR-API-001 |
| `src/Api/SlackScopeException.php` | Web API client | FR-API-001 |

## Identifiers

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Id/SlackChannelId.php` | Identifiers | FR-API-002 |
| `src/Id/SlackId.php` | Identifiers | FR-API-002 |
| `src/Id/SlackTeamId.php` | Identifiers | FR-API-002 |
| `src/Id/SlackUserId.php` | Identifiers | FR-API-002 |

## Rate limits and Messenger

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Messenger/RateLimitRetryMiddleware.php` | Rate limits and Messenger | FR-API-003 |

## Secrets and redaction

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Security/SecretRedactor.php` | Secrets and redaction | FR-SEC-001 |
| `src/Security/SlackSecret.php` | Secrets and redaction | FR-SEC-001 |

## Credentials and stores

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Credentials/InMemoryInstallationStore.php` | Credentials and stores | FR-CRED-001 |
| `src/Credentials/InstanceSettingsInstallationStore.php` | Credentials and stores | FR-CRED-001 |
| `src/Credentials/SlackAppRegistry.php` | Credentials and stores | FR-CRED-001 |
| `src/Credentials/SlackInstallation.php` | Credentials and stores | FR-CRED-001 |
| `src/Credentials/SlackInstallationKey.php` | Credentials and stores | FR-CRED-001 |
| `src/Credentials/SlackInstallationStoreInterface.php` | Credentials and stores | FR-CRED-001 |
| `src/Test/SlackInstallationStoreContractTestCase.php` | Credentials and stores | FR-CRED-002 |

## Signed ingress

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Ingress/EventsHandler.php` | Signed ingress | FR-ING-001 |
| `src/Ingress/HandleSlackEvent.php` | Signed ingress | FR-ING-001 |
| `src/Ingress/IngressController.php` | Signed ingress | FR-ING-001 |
| `src/Ingress/ReplayGuard.php` | Signed ingress | FR-ING-001 |
| `src/Ingress/ResolvedSigningSecret.php` | Signed ingress | FR-ING-001 |
| `src/Ingress/SignatureVerifier.php` | Signed ingress | FR-ING-001 |
| `src/Ingress/SigningSecretResolverInterface.php` | Signed ingress | FR-ING-001 |
| `src/Ingress/UnverifiedPayloadPeek.php` | Signed ingress | FR-ING-001 |

## Interactions router and nsk1 values

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Interaction/ActorResolver.php` | Interactions router and nsk1 values | FR-INT-001 |
| `src/Interaction/AsSlackActionHandler.php` | Interactions router and nsk1 values | FR-INT-001 |
| `src/Interaction/ButtonValue.php` | Interactions router and nsk1 values | FR-INT-001 |
| `src/Interaction/ButtonValueCodec.php` | Interactions router and nsk1 values | FR-INT-001 |
| `src/Interaction/InteractionRouter.php` | Interactions router and nsk1 values | FR-INT-001 |
| `src/Interaction/InvalidButtonValueException.php` | Interactions router and nsk1 values | FR-INT-001 |
| `src/Interaction/LegacyValuePolicy.php` | Interactions router and nsk1 values | FR-INT-001 |
| `src/Interaction/ReplyDispatcher.php` | Interactions router and nsk1 values | FR-INT-001 |
| `src/Interaction/SlackActionHandlerInterface.php` | Interactions router and nsk1 values | FR-INT-001 |
| `src/Interaction/SlackActor.php` | Interactions router and nsk1 values | FR-INT-001 |
| `src/Interaction/SlackInteraction.php` | Interactions router and nsk1 values | FR-INT-001 |
| `src/Interaction/SlackInteractionReply.php` | Interactions router and nsk1 values | FR-INT-001 |

## Account linking

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Linking/AccountLinker.php` | Account linking | FR-LINK-001 |
| `src/Linking/ConfirmLinkHandler.php` | Account linking | FR-LINK-001 |
| `src/Linking/LinkResult.php` | Account linking | FR-LINK-001 |
| `src/Linking/SlackAccountResolverInterface.php` | Account linking | FR-LINK-001 |
| `src/Linking/SlackIdentityStoreInterface.php` | Account linking | FR-LINK-001 |
| `src/Linking/SlackLink.php` | Account linking | FR-LINK-001 |

## Block Kit builder

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/BlockKit/BlockKitLimitException.php` | Block Kit builder | FR-BK-001 |
| `src/BlockKit/Blocks.php` | Block Kit builder | FR-BK-001 |
| `src/BlockKit/Mrkdwn.php` | Block Kit builder | FR-BK-001 |

## Events

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Event/SlackAccountClaimedEvent.php` | Events | FR-EVT-001 |
| `src/Event/SlackAccountUnlinkedEvent.php` | Events | FR-EVT-001 |
| `src/Event/SlackAccountVerifiedEvent.php` | Events | FR-EVT-001 |
| `src/Event/SlackActionHandledEvent.php` | Events | FR-EVT-001 |
| `src/Event/SlackConfirmationRateLimitedEvent.php` | Events | FR-EVT-001 |
| `src/Event/SlackEventReceivedEvent.php` | Events | FR-EVT-001 |
| `src/Event/SlackEventsRateLimitedEvent.php` | Events | FR-EVT-001 |
| `src/Event/SlackInstallationRevokedEvent.php` | Events | FR-EVT-001 |
| `src/Event/SlackInstallationUnhealthyEvent.php` | Events | FR-EVT-001 |
| `src/Event/SlackInteractionReceivedEvent.php` | Events | FR-EVT-001 |
| `src/Event/SlackLinkReleasedEvent.php` | Events | FR-EVT-001 |

## Notifier transport

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Notifier/SlackKitOptions.php` | Notifier transport | FR-NOT-001 |
| `src/Notifier/SlackKitTransport.php` | Notifier transport | FR-NOT-001 |
| `src/Notifier/SlackKitTransportFactory.php` | Notifier transport | FR-NOT-001 |

## UI and controllers

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Controller/AccountLinkController.php` | UI and controllers | FR-UI-001 |
| `src/Controller/FlashTrait.php` | UI and controllers | FR-UI-001 |
| `src/Controller/TestConnectionController.php` | UI and controllers | FR-UI-001 |
| `src/Form/SlackUserIdType.php` | UI and controllers | FR-UI-001 |
| `src/Resources/views/account/_link.html.twig` | UI and controllers | FR-UI-003 |
| `src/Settings/SlackSettingsNoticeProviderInterface.php` | UI and controllers | FR-UI-002 |
| `src/Settings/SlackSettingsSection.php` | UI and controllers | FR-UI-002 |
| `src/Twig/SlackKitTwigExtension.php` | UI and controllers | FR-UI-003 |

## Translations

| Source path | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Resources/translations/NowoSlackKitBundle.de.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoSlackKitBundle.en.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoSlackKitBundle.es.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoSlackKitBundle.fr.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoSlackKitBundle.it.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoSlackKitBundle.nl.yaml` | Translations | FR-I18N-001 |
| `src/Resources/translations/NowoSlackKitBundle.pt.yaml` | Translations | FR-I18N-001 |

## Sources added after the last baseline review (sweep 2026-11-16)

These 41 `src/` files were added by releases after the last review. Each row maps the file to the requirement already used for its neighbours in the same directory; the description comes from the class docblock. With them, every file under `src/` is listed in this inventory.

| Source file | Spec section | Requirement IDs |
| --- | --- | --- |
| `src/Api/ResponseUrlClient.php` | Web API client: posts a reply to an interaction `response_url`, accepting only `https://hooks.slack.com/actions/...` (checked by `SlackSecret::responseUrl()`); no redirects, timeouts, a response cap, Slack error codes mapped to `SlackApiException`; uses the logger-free HTTP client because the URL is a credential | FR-API-001 |
| `src/Api/ResponseUrlClientInterface.php` | Web API client: Answers an interaction through its `response_url` (spec 237 T000c): no bot token, valid for 30 minutes after the click. | FR-API-001 |
| `src/Api/SlackResponseUrlReply.php` | Web API client: value object of what to send to a `response_url` (spec 237 T000c): an ephemeral message for the clicker, a replacement of the clicked message, or its deletion | FR-API-001 |
| `src/Api/SlackUserDirectoryInterface.php` | Web API client: `users.info` through one installation (1.4, link-from-click). | FR-API-001 |
| `src/Api/SlackUserProfile.php` | Web API client: what `users.info` says about a member (spec 237 FR-011 / FR-012): display name, e-mail (only with `users:read.email`, personal data, never logged), bot / deactivated flags | FR-API-001 |
| `src/BlockKit/MessageUpdate.php` | Block Kit builder: pure rebuild of a clicked message for `chat.update` or a `response_url` replacement: removes the buttons and menus whose `action_id` is spent (an empty `actions` block is dropped), keeps every other block as Slack sent it and adds a context line such as "Assigned to Ana" (spec 237 T000c, FR-016) | FR-BK-001 |
| `src/Controller/ClickLinkController.php` | UI and controllers: link-from-click pages (1.4, spec 237 FR-011 / FR-013): GET `link/{token}` shows workspace and member and asks for an explicit confirm, POST (CSRF) links the identity, POST `unlink/{team}` (CSRF) removes the account's identity in one workspace; always needs the signed-in account from `SlackAccountResolverInterface`; mounted only with `linking.from_click.enabled` | FR-UI-001 |
| `src/Credentials/InMemoryInstallationBindingStore.php` | Credentials and stores: Bindings kept in memory (tests and demos only, with `credentials.store: memory`). | FR-CRED-001 |
| `src/Credentials/InMemoryInstallationStoreBoundaryListener.php` | Credentials and stores: Starts every unit of work with the initial state of the `memory` installation store (REQ-RUNTIME-002). | FR-CRED-001 |
| `src/Credentials/SlackInstallationBinding.php` | Credentials and stores: grants an owner scope the use of an installation (spec 237 FR-001); two organizations in one workspace share one installation (one token, one revocation) through two bindings | FR-CRED-001 |
| `src/Credentials/SlackInstallationBindingStoreInterface.php` | Credentials and stores: Where bindings between installations and owner scopes live (spec 237 FR-001; `installations.binding_store`). | FR-CRED-001 |
| `src/Credentials/SlackOwnerScope.php` | Credentials and stores: who may use an installation in the host's own terms (spec 237 FR-001): a type (`instance`, `organization`, `personal`...) and an opaque id that the bundle only compares exactly, never interprets | FR-CRED-001 |
| `src/DependencyInjection/Compiler/LinkingLimiterPoolPass.php` | Bundle / DI: Audit IL-L2: the linking rate limiters must keep their counters. | FR-DI-002 |
| `src/Event/SlackIdentityLinkedEvent.php` | Events: dispatched after the identity store saved a Slack identity linked to an account from a click (1.4, spec 237 FR-011); carries ids only, no e-mail | FR-EVT-001 |
| `src/Event/SlackInstallationCompletedEvent.php` | Events: an "Add to Slack" install finished, installation saved (a re-install updates its token) and bound to the owner scope (spec 237 FR-003); dispatched after the stores returned | FR-EVT-001 |
| `src/Id/SlackEnterpriseId.php` | Identifiers: A Slack Enterprise Grid organisation id (`E…`), part of an installation key when the workspace belongs to a grid. | FR-API-002 |
| `src/Install/InstallController.php` | "Add to Slack" (OAuth v2, spec 237 FR-003). | FR-INSTALL-001 |
| `src/Install/InstallStateStore.php` | Install (`@internal`): the OAuth `state` of "Add to Slack" (spec 237 FR-003): a random nonce MAC-signed with a key derived from `kernel.secret`, its record (app, owner scope, account hash, expiry) kept in the session; single use twice over (the record leaves the session, and a `symfony/lock` claim refuses a second process); a capped number of pending installs per session | FR-INSTALL-001 |
| `src/Install/SlackInstallAttempt.php` | Install: value object of one "Add to Slack" attempt for an owner scope (spec 237 FR-003), what `SlackInstallAuthorizerInterface` decides on | FR-INSTALL-001 |
| `src/Install/SlackInstallAuthorizerInterface.php` | Host hook that decides who may install an app for an owner scope (`installations.oauth.authorizer`, spec 237 FR-003). | FR-INSTALL-001 |
| `src/Install/SlackOAuthClient.php` | Install: `oauth.v2.access` client, only on `https://slack.com/api/`: client credentials in the HTTP Basic header, no redirects, timeouts and a response cap; logger-free HTTP client because the answer holds the bot token | FR-INSTALL-001 |
| `src/Install/SlackOAuthClientInterface.php` | Exchanges the one-time code of an "Add to Slack" callback for the installation's grant (`oauth.v2.access`). | FR-INSTALL-001 |
| `src/Install/SlackOAuthExchange.php` | Install: value object of the `oauth.v2.access` exchange of an install callback: the app's client credentials, the one-time code and the redirect URI used for the authorisation | FR-INSTALL-001 |
| `src/Install/SlackOAuthGrant.php` | Install: validated result of `oauth.v2.access`: bot token (null for anything else, such as a rotating token), workspace, app and scopes; a user token is never kept | FR-INSTALL-001 |
| `src/Interaction/ButtonValueCodecInterface.php` | Interactions router and nsk1 values: Signs and verifies `nsk1` button values (1.2, DDD P2). | FR-INT-001 |
| `src/Interaction/SlackActorCandidate.php` | Interactions router and nsk1 values: a verified, active link about to become the actor of a click (audit PZ-M1), what `SlackActorFilterInterface` decides on; matters most for clicks signed by a workspace's own app (`verifiedByOwnApp`) | FR-INT-001 |
| `src/Interaction/SlackActorFilterInterface.php` | Interactions router and nsk1 values: optional host hook (`installations.actor_filter`, audit PZ-M1) with the last word on whether a verified, active link may act for a click, for example only accounts of the owner scopes bound to the installation; without it every verified, active link of the workspace may act (1.2 behaviour) | FR-INT-001 |
| `src/Interaction/SlackOriginalMessage.php` | Interactions router and nsk1 values: the clicked message as Slack sent it in the verified payload (`message.text`, `message.blocks`), the input of `MessageUpdate` (spec 237 T000c) | FR-INT-001 |
| `src/Linking/AccountLinkerInterface.php` | Account linking: The account-linking state machine (1.2, DDD P2). | FR-LINK-001 |
| `src/Linking/ClickLinkPreview.php` | Account linking: What the link-from-click confirmation page shows (spec 237 FR-011): the workspace and the Slack member, by name. | FR-LINK-001 |
| `src/Linking/ClickLinkSigner.php` | Account linking (`@internal`): signs and verifies link-from-click tokens `<payload>.<mac>` (base64url, HMAC-SHA256 with its own HKDF info so a button value can never pass as a link token, lifetime at most 10 minutes, random nonce; single use is enforced by `ClickLinker` with a lock) | FR-LINK-001 |
| `src/Linking/ClickLinkToken.php` | Account linking: a verified link-from-click token: which member of which workspace, through which installation, until when, and its single-use nonce (spec 237 FR-011) | FR-LINK-001 |
| `src/Linking/ClickLinker.php` | Account linking: Link-from-click (spec 237 T000b, FR-010 – FR-013). | FR-LINK-001 |
| `src/Linking/ClickLinkerInterface.php` | Account linking: Several identities per account and linking from a click (1.4, spec 237 T000b). | FR-LINK-001 |
| `src/Linking/SlackIdentityConflictException.php` | Account linking: thrown by `SlackIdentityLinksInterface::saveLink()` when another account already holds the (workspace, member) pair; a store refuses and never replaces (audit R2-M1); hosts map their unique-key violation to it | FR-LINK-001 |
| `src/Linking/SlackIdentityLinksInterface.php` | Account linking: Several Slack identities per account, one per workspace (spec 237 FR-010, 1.4). | FR-LINK-001 |
| `src/Linking/SlackVerifiedEmailCheck.php` | Account linking: Does this account own this e-mail, verified? | FR-LINK-001 |
| `src/Linking/SlackVerifiedEmailCheckerInterface.php` | Account linking: host port of link-from-click (`linking.from_click.email_checker`, spec 237 FR-012): whether an e-mail is a verified e-mail of the account; compare case-insensitively, never treat an unverified address as a match | FR-LINK-001 |
| `src/Test/SlackIdentityLinksContractTestCase.php` | Test support: contract test case of an identity store with several identities per account (1.4, spec 237 FR-010); extend it with an empty store whose accounts `account-1` and `account-2` exist and are active (needs phpunit in the host) | FR-CRED-002 |
| `src/Test/SlackInstallationBindingStoreContractTestCase.php` | Test support: contract test case of the binding store (`installations.binding_store`, spec 237 FR-001); extend it with an empty store (needs phpunit in the host) | FR-CRED-002 |
| `src/Test/SlackMultiInstallationStoreContractTestCase.php` | The contract of an installation store that keeps several workspaces (spec 237: "Add to Slack" and own apps). | FR-CRED-002 |

## Coverage summary

| Category | Files | Mapped |
| --- | ---: | ---: |
| Bundle / DI | 7 | 7 |
| Web API client | 16 | 16 |
| Identifiers | 4 | 4 |
| Rate limits and Messenger | 1 | 1 |
| Secrets and redaction | 2 | 2 |
| Credentials and stores | 7 | 7 |
| Signed ingress | 8 | 8 |
| Interactions router and nsk1 values | 12 | 12 |
| Account linking | 6 | 6 |
| Block Kit builder | 3 | 3 |
| Events | 11 | 11 |
| Notifier transport | 3 | 3 |
| UI and controllers | 8 | 8 |
| Translations | 7 | 7 |
| Sources added after the last baseline review (sweep 2026-11-16) | 41 | 41 |
| **Total production sources** | **136** | **136** |

Audit command: `git ls-files src | wc -l` → **136** (2026-11-27, 1.7.0); every tracked file under `src/` is a row of this inventory (checked path by path, no stale rows).
