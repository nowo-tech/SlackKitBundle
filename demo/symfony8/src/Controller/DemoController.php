<?php

declare(strict_types=1);

namespace App\Controller;

use App\FakeSlack\FakeSlackApi;
use App\Slack\DemoIdentityStore;
use App\Slack\SlackClickSimulator;
use Nowo\SlackKitBundle\Api\SlackApiClientInterface;
use Nowo\SlackKitBundle\Api\SlackApiException;
use Nowo\SlackKitBundle\Api\SlackMessage;
use Nowo\SlackKitBundle\BlockKit\Blocks;
use Nowo\SlackKitBundle\Credentials\SlackInstallationKey;
use Nowo\SlackKitBundle\Id\SlackChannelId;
use Nowo\SlackKitBundle\Id\SlackTeamId;
use Nowo\SlackKitBundle\Interaction\ButtonValueCodec;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class DemoController extends AbstractController
{
    public function __construct(
        private readonly FakeSlackApi $fakeSlack,
        private readonly FormFactoryInterface $forms,
    ) {
    }

    #[Route('/', name: 'demo_home', methods: ['GET'])]
    public function home(DemoIdentityStore $identities): Response
    {
        $buttons = [];
        foreach ($this->fakeSlack->calls() as $i => $call) {
            if ($call['method'] !== 'chat.postMessage') {
                continue;
            }
            foreach ($this->buttonsOf($call['body']) as $j => $button) {
                $buttons[] = [
                    'channel'   => \is_string($call['body']['channel'] ?? null) ? $call['body']['channel'] : '?',
                    'text'      => $button['text'],
                    'action_id' => $button['action_id'],
                    'value'     => $button['value'],
                    'form'      => $this->clickForm($i, $j)->createView(),
                ];
            }
        }

        return $this->render('home.html.twig', [
            'link'      => $identities->findLinkByAccount(DemoIdentityStore::ACCOUNT),
            'calls'     => $this->fakeSlack->calls(),
            'buttons'   => $buttons,
            'post_form' => $this->simpleForm('post_demo_button', 'demo_post_button', 'Post a demo button to #ops')->createView(),
            'clear'     => $this->simpleForm('clear_fake_slack', 'demo_clear', 'Clear the fake Slack log')->createView(),
        ]);
    }

    #[Route('/demo/post-button', name: 'demo_post_button', methods: ['POST'])]
    public function postButton(Request $request, SlackApiClientInterface $api, ButtonValueCodec $codec): RedirectResponse
    {
        $this->assertSubmitted($this->simpleForm('post_demo_button', 'demo_post_button', ''), $request);
        $value   = $codec->sign('demo.ping', ['ticket' => '42'], SlackTeamId::fromString(FakeSlackApi::TEAM));
        $message = new SlackMessage('Ticket 42 needs attention', [
            Blocks::section('*Ticket 42* needs attention <!channel> & friends'),
            Blocks::actions([Blocks::button('Ping', 'demo.ping', $value, 'primary')]),
        ]);
        try {
            $api->postMessage(SlackInstallationKey::default(), SlackChannelId::fromString(FakeSlackApi::CHANNEL), $message);
            $this->addFlash('success', 'Posted a message with a signed demo.ping button to C00000001.');
        } catch (SlackApiException $e) {
            $this->addFlash('error', 'Slack refused the message: ' . $e->errorCode . ' (run make seed).');
        }

        return $this->redirectToRoute('demo_home');
    }

    #[Route('/demo/click/{call}/{button}', name: 'demo_click', requirements: ['call' => '\d+', 'button' => '\d+'], methods: ['POST'])]
    public function click(Request $request, int $call, int $button, SlackClickSimulator $simulator, DemoIdentityStore $identities): RedirectResponse
    {
        $this->assertSubmitted($this->clickForm($call, $button), $request);
        $calls   = $this->fakeSlack->calls();
        $buttons = isset($calls[$call]) ? $this->buttonsOf($calls[$call]['body']) : [];
        if (!isset($buttons[$button])) {
            $this->addFlash('error', 'That button is gone from the fake Slack log.');

            return $this->redirectToRoute('demo_home');
        }
        $clicker = $identities->findLinkByAccount(DemoIdentityStore::ACCOUNT)?->slackUserId->value ?? FakeSlackApi::USER;
        $channel = \is_string($calls[$call]['body']['channel'] ?? null) ? $calls[$call]['body']['channel'] : FakeSlackApi::DM;
        $answer  = $simulator->click($buttons[$button]['action_id'], $buttons[$button]['value'], $clicker, $channel);
        $this->addFlash($answer['status'] < 300 ? 'success' : 'error', sprintf('Slack ingress answered %d: %s', $answer['status'], $answer['body'] !== '' ? $answer['body'] : '(empty)'));

        return $this->redirectToRoute('demo_home');
    }

    #[Route('/demo/clear', name: 'demo_clear', methods: ['POST'])]
    public function clear(Request $request): RedirectResponse
    {
        $this->assertSubmitted($this->simpleForm('clear_fake_slack', 'demo_clear', ''), $request);
        $this->fakeSlack->clear();

        return $this->redirectToRoute('demo_home');
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<array{text: string, action_id: string, value: string}>
     */
    private function buttonsOf(array $body): array
    {
        $blocks = json_decode(\is_string($body['blocks'] ?? null) ? $body['blocks'] : (string) json_encode($body['blocks'] ?? []), true);
        $out    = [];
        foreach (\is_array($blocks) ? $blocks : [] as $block) {
            foreach (\is_array($block) && \is_array($block['elements'] ?? null) ? $block['elements'] : [] as $element) {
                if (\is_array($element) && ($element['type'] ?? null) === 'button' && \is_string($element['value'] ?? null) && \is_string($element['action_id'] ?? null)) {
                    $out[] = ['text' => (string) ($element['text']['text'] ?? ''), 'action_id' => $element['action_id'], 'value' => $element['value']];
                }
            }
        }

        return $out;
    }

    /**
     * @return FormInterface<mixed>
     */
    private function simpleForm(string $name, string $route, string $label): FormInterface
    {
        return $this->forms->createNamedBuilder($name, options: ['action' => $this->generateUrl($route), 'csrf_token_id' => $name])
            ->add('submit', SubmitType::class, ['label' => $label, 'attr' => ['class' => 'btn btn-outline-primary']])
            ->getForm();
    }

    /**
     * @return FormInterface<mixed>
     */
    private function clickForm(int $call, int $button): FormInterface
    {
        return $this->forms->createNamedBuilder('click_' . $call . '_' . $button, options: ['action' => $this->generateUrl('demo_click', ['call' => $call, 'button' => $button]), 'csrf_token_id' => 'demo_click'])
            ->add('confirm', HiddenType::class, ['data' => '1'])
            ->add('submit', SubmitType::class, ['label' => 'Click as the linked member', 'attr' => ['class' => 'btn btn-sm btn-primary']])
            ->getForm();
    }

    /**
     * @param FormInterface<mixed> $form
     */
    private function assertSubmitted(FormInterface $form, Request $request): void
    {
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw $this->createAccessDeniedException('Invalid form.');
        }
    }
}
