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
| `src/Api/ResponseUrlClient.php` | Web API client: Posts to an interaction `response_url` (`https://hooks.slack.com/actions/…` only, enforced by SlackSecret::responseUr... | FR-API-001 |
| `src/Api/ResponseUrlClientInterface.php` | Web API client: Answers an interaction through its `response_url` (spec 237 T000c): no bot token, valid for 30 minutes after the click. | FR-API-001 |
| `src/Api/SlackResponseUrlReply.php` | Web API client: What to send to an interaction's `response_url` (spec 237 T000c): an ephemeral message for the clicker, a replacement... | FR-API-001 |
| `src/Api/SlackUserDirectoryInterface.php` | Web API client: `users.info` through one installation (1.4, link-from-click). | FR-API-001 |
| `src/Api/SlackUserProfile.php` | Web API client: What `users.info` says about a member (spec 237 FR-011 / FR-012): the name to show, the e-mail (only with `users:read... | FR-API-001 |
| `src/BlockKit/MessageUpdate.php` | Block Kit builder: Rebuilds a clicked message without its spent buttons and with a context line such as "Assigned to Ana" (spec 237 T000... | FR-BK-001 |
| `src/Controller/ClickLinkController.php` | UI and controllers: Link-from-click pages (1.4, spec 237 FR-011 / FR-013): `GET …/link/{token}` shows the workspace and the Slack member... | FR-UI-001 |
| `src/Credentials/InMemoryInstallationBindingStore.php` | Credentials and stores: Bindings kept in memory (tests and demos only, with `credentials.store: memory`). | FR-CRED-001 |
| `src/Credentials/InMemoryInstallationStoreBoundaryListener.php` | Credentials and stores: Starts every unit of work with the initial state of the `memory` installation store (REQ-RUNTIME-002). | FR-CRED-001 |
| `src/Credentials/SlackInstallationBinding.php` | Credentials and stores: Gives an owner scope the use of an installation (spec 237 FR-001): two organizations in the same workspace share one... | FR-CRED-001 |
| `src/Credentials/SlackInstallationBindingStoreInterface.php` | Credentials and stores: Where bindings between installations and owner scopes live (spec 237 FR-001; `installations.binding_store`). | FR-CRED-001 |
| `src/Credentials/SlackOwnerScope.php` | Credentials and stores: Who may use an installation, in the host's own terms (spec 237 FR-001): a type such as `instance`, `organization` or... | FR-CRED-001 |
| `src/DependencyInjection/Compiler/LinkingLimiterPoolPass.php` | Bundle / DI: Audit IL-L2: the linking rate limiters must keep their counters. | FR-DI-002 |
| `src/Event/SlackIdentityLinkedEvent.php` | Events: A Slack identity was linked to an account from a click (1.4, spec 237 FR-011): dispatched after the identity store sa... | FR-EVT-001 |
| `src/Event/SlackInstallationCompletedEvent.php` | Events: An "Add to Slack" install finished: the installation was saved (a re-install updated its token) and bound to the owne... | FR-EVT-001 |
| `src/Id/SlackEnterpriseId.php` | Identifiers: A Slack Enterprise Grid organisation id (`E…`), part of an installation key when the workspace belongs to a grid. | FR-API-002 |
| `src/Install/InstallController.php` | "Add to Slack" (OAuth v2, spec 237 FR-003). | FR-INSTALL-001 |
| `src/Install/InstallStateStore.php` | The `state` of "Add to Slack" (spec 237 FR-003): a random nonce MAC-signed with a key derived from `kernel.secret`, w... | FR-INSTALL-001 |
| `src/Install/SlackInstallAttempt.php` | Someone wants to install an app for an owner scope ("Add to Slack", spec 237 FR-003): what SlackInstallAuthorizerInte... | FR-INSTALL-001 |
| `src/Install/SlackInstallAuthorizerInterface.php` | Host hook that decides who may install an app for an owner scope (`installations.oauth.authorizer`, spec 237 FR-003). | FR-INSTALL-001 |
| `src/Install/SlackOAuthClient.php` | `oauth.v2.access` on `https://slack.com/api/` only: client credentials in the HTTP Basic header, no redirects, timeou... | FR-INSTALL-001 |
| `src/Install/SlackOAuthClientInterface.php` | Exchanges the one-time code of an "Add to Slack" callback for the installation's grant (`oauth.v2.access`). | FR-INSTALL-001 |
| `src/Install/SlackOAuthExchange.php` | The `oauth.v2.access` exchange of an "Add to Slack" callback: the app's client credentials, the one-time code and the... | FR-INSTALL-001 |
| `src/Install/SlackOAuthGrant.php` | What `oauth.v2.access` granted, validated: the bot token (null when Slack returned something else, such as a rotating... | FR-INSTALL-001 |
| `src/Interaction/ButtonValueCodecInterface.php` | Interactions router and nsk1 values: Signs and verifies `nsk1` button values (1.2, DDD P2). | FR-INT-001 |
| `src/Interaction/SlackActorCandidate.php` | Interactions router and nsk1 values: A verified, active link about to become the actor of a click (spec 237, audit PZ-M1): what SlackActorFilterInterface... | FR-INT-001 |
| `src/Interaction/SlackActorFilterInterface.php` | Interactions router and nsk1 values: Optional host hook (`installations.actor_filter`, spec 237 / audit PZ-M1): the last word on whether a verified, activ... | FR-INT-001 |
| `src/Interaction/SlackOriginalMessage.php` | Interactions router and nsk1 values: The clicked message as Slack sent it in the verified payload (`message.text`, `message.blocks`): what MessageUpdate r... | FR-INT-001 |
| `src/Linking/AccountLinkerInterface.php` | Account linking: The account-linking state machine (1.2, DDD P2). | FR-LINK-001 |
| `src/Linking/ClickLinkPreview.php` | Account linking: What the link-from-click confirmation page shows (spec 237 FR-011): the workspace and the Slack member, by name. | FR-LINK-001 |
| `src/Linking/ClickLinkSigner.php` | Account linking: Signs and verifies link-from-click tokens (spec 237 FR-011): `<payload>.<mac>`, base64url, HMAC-SHA256 with a key der... | FR-LINK-001 |
| `src/Linking/ClickLinkToken.php` | Account linking: A verified link-from-click token (spec 237 FR-011): which member of which workspace, through which installation, unti... | FR-LINK-001 |
| `src/Linking/ClickLinker.php` | Account linking: Link-from-click (spec 237 T000b, FR-010 – FR-013). | FR-LINK-001 |
| `src/Linking/ClickLinkerInterface.php` | Account linking: Several identities per account and linking from a click (1.4, spec 237 T000b). | FR-LINK-001 |
| `src/Linking/SlackIdentityConflictException.php` | Account linking: Thrown by SlackIdentityLinksInterface::saveLink() when another account holds the (workspace, member) pair: a store re... | FR-LINK-001 |
| `src/Linking/SlackIdentityLinksInterface.php` | Account linking: Several Slack identities per account, one per workspace (spec 237 FR-010, 1.4). | FR-LINK-001 |
| `src/Linking/SlackVerifiedEmailCheck.php` | Account linking: Does this account own this e-mail, verified? | FR-LINK-001 |
| `src/Linking/SlackVerifiedEmailCheckerInterface.php` | Account linking: Host port of link-from-click (`linking.from_click.email_checker`, spec 237 FR-012): whether the e-mail is a **verifie... | FR-LINK-001 |
| `src/Test/SlackIdentityLinksContractTestCase.php` | The contract of an identity store with several identities per account (1.4, spec 237 FR-010): extend it in your test... | FR-CRED-002 |
| `src/Test/SlackInstallationBindingStoreContractTestCase.php` | The binding store contract (spec 237 FR-001, `installations.binding_store`): extend it in your test suite and return... | FR-CRED-002 |
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
