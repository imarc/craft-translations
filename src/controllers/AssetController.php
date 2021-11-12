<?php

namespace acclaro\translations\controllers;

use Craft;
use craft\base\Element;
use craft\elements\Asset;
use acclaro\translations\Translations;
use acclaro\translations\Constants;

class AssetController extends BaseController
{
    /**
     * Edit an asset draft
     *
     * @param array $variables
     * @return void
     */
    public function actionEditDraft(array $variables = array())
    {
        $data = Craft::$app->getRequest()->resolve()[1];
        $siteService = Craft::$app->getSites();

        $site = $siteService->getSiteByHandle($data['site'] ?? $siteService->getCurrentSite()->handle);

        if (empty($data['elementId'])) {
            Craft::$app->getSession()->setError(Translations::$plugin->translator->translate('app', 'Param “{name}” doesn’t exist.', array('name' => 'elementId')));
            return;
        }

        $assetId = $data['elementId'];
        $asset = Craft::$app->assets->getAssetById($assetId, $site->id);

        $variables['filename'] = $asset->getFilename(false);
        $variables['assetId'] = $assetId;
        $variables['asset'] = $asset;
        $variables['selectedSubnavItem'] = 'orders';
        
        $draft = Translations::$plugin->assetDraftRepository->getDraftById($data['draftId']);
        $variables['element'] = $draft;

        $variables['selectedSite'] = isset($data['site']) ? $site : $siteService->getSiteById($draft->site);

        $variables['file'] = Translations::$plugin->fileRepository->getFileByDraftId($draft->draftId);

        $variables['dimensions'] = $asset->dimensions;
        $variables['assetUrl'] = $asset->url;
        $variables['canReplaceFile'] = $asset->isEditable;
        $variables['previewHtml'] = $asset->editorHtml;
        $variables['volume'] = $asset->volume;
        $variables['formattedSize'] = $asset->formattedSize;
        $variables['formattedSizeInBytes'] = $asset->formattedSizeInBytes;

        $this->renderTemplate('translations/assets/_editDraft', $variables);
    }

    /**
     * Save an asset draft record
     *
     * @return void
     */
    public function actionSaveDraft()
    {
        $this->requirePostRequest();
        
        $assetId = $this->request->getParam('assetId');
        $siteId = $this->request->getParam('site');
        $asset = Craft::$app->assets->getAssetById($assetId, $siteId);

        if (!$asset) {
            Craft::$app->getSession()->setError(Translations::$plugin->translator->translate('app', 'No Asset exists with the ID “{id}”.', array('id' => $assetId)));
            return;
        }

        $draftId = $this->request->getParam('draftId');
        if ($draftId) {
            $draft = Translations::$plugin->assetDraftRepository->getDraftById($draftId);

            if (!$draft) {
                Craft::$app->getSession()->setError(Translations::$plugin->translator->translate('app', 'No draft exists with the ID “{id}”.', array('id' => $draftId)));
                return;
            }
        } else {
            $draft = Translations::$plugin->assetDraftRepository->makeNewDraft();
        }
        
        $draft->id = $asset->id;
        $draft->title = $this->request->getParam('title') ?? $asset->title;
        $draft->site = $siteId;

        $fields = $this->request->getParam('fields') ?? [];

        if ($fields) {
            $draft->setFieldValues($fields);
        }
        
        Craft::$app->getElements()->saveElement($draft);
        
        if (Translations::$plugin->assetDraftRepository->saveDraft($draft, $fields)) {
            Craft::$app->getSession()->setNotice(Translations::$plugin->translator->translate('app', 'Draft saved.'));

            $this->redirect($draft->getCpEditUrl(), 302, true);
        } else {
            Craft::$app->getSession()->setError(Translations::$plugin->translator->translate('app', 'Couldn’t save draft.'));

            Craft::$app->urlManager->setRouteParams(array(
                'asset' => $draft
            ));
        }
    }

    /**
     * Publish an asset draft record
     *
     * @return void
     */
    public function actionPublishDraft()
    {
        $this->requirePostRequest();

        $draftId = Craft::$app->getRequest()->getParam('draftId');
        $assetId = Craft::$app->getRequest()->getParam('assetId');
        $draft = Translations::$plugin->assetDraftRepository->getDraftById($draftId);

        if (!$draft) {
            Craft::$app->getSession()->setError(Translations::$plugin->translator->translate('app', 'No draft exists with the ID “{id}”.', array('id' => $draftId)));
            return;
        }

        $asset = Craft::$app->assets->getAssetById($assetId, $draft->site);

        if (!$asset) {
            Craft::$app->getSession()->setError(Translations::$plugin->translator->translate('app', 'No asset exists with the ID “{id}”.', array('id' => $draft->assetId)));
            return;
        }

        $draft->title = $this->request->getParam('title') ?? $asset->title;
        $draft->newFilename = $this->request->getParam('filename');

        $fields = $this->request->getParam('fields') ?? [];

        if ($fields) {
            $draft->setFieldValues($fields);
        }
        
        // restore the original name
        $draft->name = $asset->title;

        $file = Translations::$plugin->fileRepository->getFileByDraftId($draftId, $asset->id);

        if ($file) {
            $order = Translations::$plugin->orderRepository->getOrderById($file->orderId);

            $file->status = Constants::ORDER_STATUS_PUBLISHED;
            $file->draftId = 0;

            Translations::$plugin->fileRepository->saveFile($file);

            $areAllFilesPublished = true;

            foreach ($order->files as $file) {
                if ($file->status !== Constants::ORDER_STATUS_PUBLISHED) {
                    $areAllFilesPublished = false;
                    break;
                }
            }

            if ($areAllFilesPublished) {
                $order->status = Constants::ORDER_STATUS_PUBLISHED;

                Translations::$plugin->orderRepository->saveOrder($order);
            }
        }

        if (Translations::$plugin->assetDraftRepository->publishDraft($draft)) {
            $this->redirect($asset->getCpEditUrl(), 302, true);

            Craft::$app->getSession()->setNotice(Translations::$plugin->translator->translate('app', 'Draft published.'));

            return Translations::$plugin->assetDraftRepository->deleteDraft($draft);
        } else {
            Craft::$app->getSession()->setError(Translations::$plugin->translator->translate('app', 'Couldn’t publish draft.'));

            // Send the draft back to the template
            Craft::$app->urlManager->setRouteParams(array(
                'asset' => $draft
            ));
        }
    }

    /**
     * Delete an asset draft record
     *
     * @return void
     */
    public function actionDeleteDraft()
    {
        $this->requirePostRequest();

        $draftId = Craft::$app->getRequest()->getParam('draftId');
        $draft = Translations::$plugin->assetDraftRepository->getDraftById($draftId);

        if (!$draft) {
            Craft::$app->getSession()->setError(Translations::$plugin->translator->translate('app', 'No draft exists with the ID “{id}”.', array('id' => $draftId)));
            return;
        }

        $asset = Translations::$plugin->assetDraftRepository->getAssetById($draft->assetId);
        $url = $asset->getCpEditUrl();
        $elementId = $draft->assetId;

        Translations::$plugin->assetDraftRepository->deleteDraft($draft);

        Translations::$plugin->fileRepository->delete($draftId, $elementId);

        Craft::$app->getSession()->setError(Translations::$plugin->translator->translate('app', 'Draft deleted.'));

        return $this->redirect($url, 302, true);
    }
}