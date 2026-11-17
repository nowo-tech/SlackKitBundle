<?php

declare(strict_types=1);

namespace App\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * A single POST action of the demo (post a demo button, clear the log, click a fake Slack button): a CSRF token and a
 * submit button, plus an optional hidden `confirm` field. FormKit profile `demo` (REQ-DEMO-015).
 *
 * @extends AbstractType<null>
 */
#[FormKitConfig('demo')]
final class DemoActionType extends AbstractType
{
    use FormOptionsTrait;

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['with_confirm']) {
            $this->addWithDefaults($builder, 'confirm', HiddenType::class, ['data' => '1']);
        }
        $this->addWithDefaults($builder, 'submit', SubmitType::class, [
            'label' => $options['submit_label'],
            'translation_domain' => false,
            'attr' => ['class' => $options['submit_class']],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection' => true,
            'submit_label' => 'Submit',
            'submit_class' => 'nowo-ui-btn nowo-ui-btn--outline',
            'with_confirm' => false,
        ]);
        $resolver->setAllowedTypes('submit_label', 'string');
        $resolver->setAllowedTypes('submit_class', 'string');
        $resolver->setAllowedTypes('with_confirm', 'bool');
    }
}
