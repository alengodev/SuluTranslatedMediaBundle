<?php

declare(strict_types=1);

namespace Alengo\SuluTranslatedMediaBundle\Admin;

use Sulu\Bundle\AdminBundle\Admin\Admin;
use Sulu\Bundle\AdminBundle\Admin\View\ToolbarAction;
use Sulu\Bundle\AdminBundle\Admin\View\ViewBuilderFactoryInterface;
use Sulu\Bundle\AdminBundle\Admin\View\ViewCollection;
use Sulu\Bundle\MediaBundle\Admin\MediaAdmin as SuluMediaAdmin;

class MediaAdmin extends Admin
{
    public function __construct(
        private readonly ViewBuilderFactoryInterface $viewBuilderFactory,
        private readonly string $formKey,
        private readonly string $resourceKey,
        private readonly string $tabTitle,
    ) {
    }

    public function configureViews(ViewCollection $viewCollection): void
    {
        if (!$viewCollection->has('sulu_media.form.details')) {
            return;
        }

        $detailsTabOrder = $viewCollection->get('sulu_media.form.details')->getView()->getOption('tabOrder');

        $viewCollection->add(
            $this->viewBuilderFactory
                ->createFormViewBuilder('alengo_translated_media.media_additional_data_form', '/additional-data')
                ->setResourceKey($this->resourceKey)
                ->setFormKey($this->formKey)
                ->setTabTitle($this->tabTitle)
                ->addToolbarActions([new ToolbarAction('sulu_admin.save')])
                ->setTabOrder($detailsTabOrder + 1)
                ->setParent(SuluMediaAdmin::EDIT_FORM_VIEW),
        );
    }

    public static function getPriority(): int
    {
        return SuluMediaAdmin::getPriority() - 1;
    }
}
