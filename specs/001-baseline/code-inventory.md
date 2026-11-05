# Code inventory — 001-baseline

**Last audited**: 2026-11-05 (SEC-004 FS fixes)
**Scope**: every production file under `src/` (94 files, `find src -type f | wc -l`). Tests, demo and generated caches are out of scope.

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
