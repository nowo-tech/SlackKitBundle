<?php

declare(strict_types=1);

namespace Nowo\SlackKitBundle\Form;

use Nowo\SlackKitBundle\Id\SlackUserId;
use Nowo\SlackKitBundle\NowoSlackKitBundle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Regex;

/**
 * The "Slack member id" form of the account link card (CSRF protected, validated against {@see SlackUserId::PATTERN}).
 *
 * @extends AbstractType<array<string, mixed>>
 */
final class SlackUserIdType extends AbstractType
{
    /** CSRF token id. */
    public const CSRF_TOKEN_ID = 'nowo_slack_kit_link_claim';

    /**
     * One text field, `slack_user_id`.
     *
     * @param array<string, mixed> $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('slack_user_id', TextType::class, [
            'label'       => 'link.form.slack_user_id',
            'help'        => 'link.form.slack_user_id_help',
            'attr'        => ['autocomplete' => 'off', 'spellcheck' => 'false', 'maxlength' => 32],
            'constraints' => [new NotBlank(), new Regex(pattern: SlackUserId::PATTERN, message: 'A Slack member id starts with U (or W) followed by capital letters and digits.')],
        ]);
    }

    /**
     * Translation domain and CSRF token id.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'translation_domain' => NowoSlackKitBundle::TRANSLATION_DOMAIN,
            'csrf_token_id'      => self::CSRF_TOKEN_ID,
        ]);
    }

    /**
     * `nowo_slack_kit_link`.
     */
    public function getBlockPrefix(): string
    {
        return 'nowo_slack_kit_link';
    }
}
