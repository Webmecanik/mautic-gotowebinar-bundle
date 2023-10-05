<?php

namespace MauticPlugin\MauticCitrixBundle\Integration;

use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilder;

/**
 * Class HubspotIntegration.
 */
class GotowebinarIntegration extends CitrixAbstractIntegration
{
    /**
     * {@inheritdoc}
     *
     * @return string
     */
    public function getName()
    {
        return 'Gotowebinar';
    }

    /**
     * @return string
     */
    public function getDisplayName()
    {
        return 'GoToWebinar';
    }

    /**
     * @param FormBuilder $builder
     * @param array       $data
     * @param string      $formArea
     */
    public function appendToForm(&$builder, $data, $formArea)
    {
        if ('features' == $formArea) {
            $builder->add(
                'downloadOnlyApprovedRegistrants',
                ChoiceType::class,
                [
                    'choices'     => [
                        'plugin.citrix.config.downloadApprovedOnly.choice' => 'downloadOnlyApprovedRegistrants',
                    ],
                    'expanded'    => true,
                    'multiple'    => true,
                    'label'       => 'plugin.citrix.config.downloadApprovedOnly',
                    'label_attr'  => ['class' => 'control-label'],
                    'placeholder' => false,
                    'required'    => false,
                    'attr'        => [
                        'onclick' => 'Mautic.postForm(mQuery(\'form[name="integration_details"]\'),\'\');',
                    ],
                ]
            );
        }
    }
}
