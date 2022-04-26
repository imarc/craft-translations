<?php
/**
 * Translations for Craft plugin for Craft CMS 3.x
 *
 * Translations for Craft eliminates error prone and costly copy/paste workflows for launching human translated Craft CMS web content.
 *
 * @link      http://www.acclaro.com/
 * @copyright Copyright (c) 2018 Acclaro
 */

namespace acclaro\translations\controllers;

use Craft;
use craft\helpers\Path;
use craft\web\Controller;
use craft\helpers\Assets;
use craft\elements\Asset;
use craft\web\UploadedFile;
use craft\elements\GlobalSet;
use craft\helpers\FileHelper;
use craft\helpers\ArrayHelper;
use craft\helpers\ElementHelper;
use yii\web\NotFoundHttpException;

use acclaro\translations\Constants;
use acclaro\translations\Translations;
use acclaro\translations\services\job\ImportFiles;

/**
 * @author    Acclaro
 * @package   Translations
 * @since     1.0.0
 */
class FilesController extends Controller
{
    protected $allowAnonymous = ['actionImportFile', 'actionExportFile', 'actionCreateExportZip'];

    /**
     * @var Order
     */
    protected $order;

    /**
	 * Allowed types of site images.
	 *
	 * @var array
	 */
	private $_allowedTypes = Constants::FILE_FORMAT_ALLOWED;

    public function actionCreateExportZip()
    {
        $params = Craft::$app->getRequest()->getRequiredBodyParam('params');

        $fileFormat = $params['format'] ?? Constants::FILE_FORMAT_XML;

        $order = Translations::$plugin->orderRepository->getOrderById($params['orderId']);

        $errors = array();

        $orderAttributes = $order->getAttributes();

        //Filename Zip Folder
        $zipName = $this->getZipName($orderAttributes);

        // Set destination zip
        $zipDest = Craft::$app->path->getTempPath() . '/' . $zipName . '.' . Constants::FILE_FORMAT_ZIP;

        // Create zip
        $zip = new \ZipArchive();

        // Open zip
        if ($zip->open($zipDest, $zip::CREATE) !== true)
        {
            $errors[] = 'Unable to create zip file: '.$zipDest;
            Craft::error('['. __METHOD__ .'] Unable to create zip file: '.$zipDest, 'translations');
            return $this->asFailure(null, $errors);
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        //Iterate over each file on this order
        if ($order->files)
        {
            foreach ($order->GetFiles() as $file)
            {
                // skip failed files
                if ($file->isCanceled()) continue;

                $element = Craft::$app->elements->getElementById($file->elementId, null, $file->sourceSite);

                $targetSite = $file->targetSite;

                if ($element instanceof GlobalSet) {
                    $fileName = $file->elementId . '-' . ElementHelper::normalizeSlug($element->name) .
                        '-' . $targetSite . '.' . $fileFormat;
                } else if ($element instanceof Asset) {
                    $assetFilename = $element->getFilename();
                    $fileInfo = pathinfo($element->getFilename());
                    $fileName = $file->elementId . '-' . basename($assetFilename,'.'.$fileInfo['extension']) . '-' . $targetSite . '.' . $fileFormat;
                } else {
                    $fileName = $file->elementId . '-' . $element->slug . '-' . $targetSite . '.' . $fileFormat;
                }

                if ($fileFormat === Constants::FILE_FORMAT_JSON) {
                    $fileContent = Translations::$plugin->elementToFileConverter->xmlToJson($file->source);
                } else if ($fileFormat === Constants::FILE_FORMAT_CSV) {
                    $fileContent = Translations::$plugin->elementToFileConverter->xmlToCsv($file->source);
                } else {
                    $fileContent = $file->source;
                }

                if ($order->includeTmFiles && $file->hasTmMissAlignments(true)) $fileName = "source/" . $fileName;

                if (! $fileContent || !$zip->addFromString($fileName, $fileContent)) {
                    $errors[] = 'There was an error adding the file '.$fileName.' to the zip: '.$zipName;
                    Craft::error( '['. __METHOD__ .'] There was an error adding the file '.$fileName.' to the zip: '.$zipName, 'translations' );
                } else if ($order->includeTmFiles && $file->hasTmMissAlignments(true)) {
                    $tmFile = $file->getTmMissAlignmentFile();
                    $fileName = $tmFile['fileName'];

                    if (! $zip->addFromString("references/" . $fileName, $tmFile['fileContent'])) {
                        $errors[] = 'There was an error adding the file '.$fileName.' to the zip: '.$zipName;
                        Craft::error( '['. __METHOD__ .'] There was an error adding the file '.$fileName.' to the zip: '.$zipName, 'translations' );
                    }

                    $file->reference = $tmFile['fileContent'];
                }

                if ($file->isNew() || $file->isModified() || $file->isPublished()) {
                    $file->status = Constants::FILE_STATUS_IN_PROGRESS;
                }
                Translations::$plugin->fileRepository->saveFile($file);
            }

            if ($order->status !== ($newStatus = Translations::$plugin->orderRepository->getNewStatus($order))) {
                $order->status = $newStatus;
                $order->logActivity(sprintf('Order status changed to \'%s\'', $order->getStatusLabel()));
            }
        }

        // Close zip
        $zip->close();

        if(count($errors) > 0)
        {
            $transaction->rollBack();
            return $this->asFailure(null, $errors);
        }

        if (Craft::$app->getElements()->saveElement($order, true, true, false)) {
            $transaction->commit();
            return $this->asSuccess(null, ['translatedFiles' => $zipDest]);
        } else {
            $transaction->rollBack();
            return $this->asFailure(null, []);
        }

    }

    /**
     * Export Functionlity
	 * Sends the zip file created to the user
     */
    public function actionExportFile()
    {
        $filename = Craft::$app->getRequest()->getRequiredQueryParam('filename');
        if (!is_file($filename) || !Path::ensurePathIsContained($filename)) {
            throw new NotFoundHttpException(Craft::t('app', 'Invalid file name: {filename}', [
                'filename' => $filename
			]));
        }

        Craft::$app->getResponse()->sendFile($filename, null, ['inline' => true]);

        return FileHelper::unlink($filename);
    }

    public function actionImportFile()
    {
        $this->requireLogin();
        $this->requirePostRequest();

        if (!Translations::$plugin->userRepository->userHasAccess('translations:orders:import')) {
            return;
        }

        $file = UploadedFile::getInstanceByName('zip-upload');

        //Get Order Data
        $orderId = Craft::$app->getRequest()->getParam('orderId');
        $sourceChangedElements = explode(",", Craft::$app->getRequest()->getParam('elements'));

        $this->order = Translations::$plugin->orderRepository->getOrderById($orderId);

        $totalWordCount = ($this->order->wordCount * count($this->order->getTargetSitesArray()));

        $total_files = (count($this->order->files) * count($this->order->getTargetSitesArray()));

        try {
            // Make sure a file was uploaded
            if ($file && $file->size > 0) {
                if (!in_array($file->extension, $this->_allowedTypes)) {
                    Craft::$app->getSession()->set('fileImportError', 1);
                    $this->showUserMessages("'$file->name' is not a supported translation file type. Please submit a [ZIP, XML, JSON, CSV] file.");
                } else {
                    //If is a Zip File
                    if ($file->extension === Constants::FILE_FORMAT_ZIP) {
                        //Unzip File ZipArchive
                        $zip = new \ZipArchive();

                        $assetPath = $file->saveAsTempFile();

                        if ($zip->open($assetPath)) {
                            $xmlPath = $assetPath.$orderId;

                            $zip->extractTo($xmlPath);

                            $fileName = preg_replace('/\\.[^.\\s]{3,4}$/', '', Assets::prepareAssetName($file->name));

                            $files = FileHelper::findFiles($assetPath.$orderId);

                            $assetIds = [];
                            $fileNames = [];
                            $fileInfo = null;

                            foreach ($files as $key => $file) {
                                if (! is_bool(strpos($file, '__MACOSX')) || strpos($file, '/references/') > -1) {
                                    unlink($file);

                                    continue;
                                }

                                $filename = Assets::prepareAssetName($file);

                                $fileInfo = pathinfo($filename);

                                $uploadVolumeId = ArrayHelper::getValue(Translations::getInstance()->getSettings(), 'uploadVolume');

                                $folder = Craft::$app->getAssets()->getRootFolderByVolumeId($uploadVolumeId);

                                $pathInfo = pathinfo($file);

                                $compatibleFilename = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '.' . Constants::FILE_FORMAT_TXT;

                                rename($file, $compatibleFilename);

                                $asset = new Asset();
                                $asset->tempFilePath = $compatibleFilename;
                                $asset->filename = $compatibleFilename;
                                $asset->newFolderId = $folder->id;
                                $asset->volumeId = $folder->volumeId;
                                $asset->avoidFilenameConflicts = true;
                                $asset->uploaderId = Craft::$app->getUser()->getId();
                                $asset->setScenario(Asset::SCENARIO_CREATE);

                                if (! Craft::$app->getElements()->saveElement($asset)) {
                                    $errors = $asset->getFirstErrors();

                                    return $this->asFailure(Craft::t('app', 'Failed to save the asset:') . ' ' . implode(";\n", $errors));
                                }

                                $assetIds[] = $asset->id;
                                $fileInfo['basename'] ? $fileNames[$asset->id] = $fileInfo['basename'] : '';
                            }

                            FileHelper::removeDirectory($assetPath.$orderId.'/'.$fileName);

                            $zip->close();

                            // Process files via job or directly based on order "size"
                            if ($totalWordCount > Constants::WORD_COUNT_LIMIT) {
                                $job = Craft::$app->queue->push(new ImportFiles([
                                    'description' => Constants::JOB_IMPORTING_FILES,
                                    'orderId' => $orderId,
                                    'totalFiles' => $total_files,
                                    'assets' => $assetIds,
                                    'fileFormat' => $fileInfo['extension'],
                                    'fileNames' => $fileNames,
                                    'discardElements' => $sourceChangedElements
                                ]));

                                if ($job) {
                                    $queueOrders = Craft::$app->getSession()->get('queueOrders');
                                    $queueOrders[$job] = $orderId;
                                    Craft::$app->getSession()->set('queueOrders', $queueOrders);
                                    Craft::$app->getSession()->set('importQueued', "1");
                                    $params = [
                                        'id' => (int) $job,
                                        'notice' => 'Done updating translation drafts',
                                        'url' => Constants::URL_ORDER_DETAIL . $orderId
                                    ];
                                    Craft::$app->getView()->registerJs('$(function(){ Craft.Translations.trackJobProgressById(true, false, '. json_encode($params) .'); });');
                                }
                                $this->showUserMessages("File queued for import. Check activity log for any errors.", true);
                            } else {
                                $fileSvc = new ImportFiles();
                                $success = true;
                                foreach ($assetIds as $key => $id) {
                                    $a = Craft::$app->getAssets()->getAssetById($id);
                                    $res = $fileSvc->processFile($a, $this->order, $fileInfo['extension'], $fileNames, $sourceChangedElements);
                                    Craft::$app->getElements()->deleteElement($a);
                                    if ($res === false) $success = false;
                                }
                                if (! $success) {
                                    $this->showUserMessages("Error importing file. Please check activity log for details.");
                                } else {
                                    $this->showUserMessages("File uploaded successfully", true);
                                }
                            }
                        } else {
                            Craft::$app->getSession()->set('fileImportError', 1);
                            $this->showUserMessages("Unable to unzip ". $file->name ." Operation not permitted or Decompression Failed ");
                        }
                    } else {
                        $filename = Assets::prepareAssetName($file->name);

                        $uploadVolumeId = ArrayHelper::getValue(Translations::getInstance()->getSettings(), 'uploadVolume');

                        $folder = Craft::$app->getAssets()->getRootFolderByVolumeId($uploadVolumeId);

                        $compatibleFilename = $file->tempName . '.' . Constants::FILE_FORMAT_TXT;

                        rename($file->tempName, $compatibleFilename);

                        $asset = new Asset();
                        $asset->tempFilePath = $compatibleFilename;
                        $asset->filename = $compatibleFilename;
                        $asset->newFolderId = $folder->id;
                        $asset->volumeId = $folder->volumeId;
                        $asset->avoidFilenameConflicts = true;
                        $asset->uploaderId = Craft::$app->getUser()->getId();
                        $asset->setScenario(Asset::SCENARIO_CREATE);

                        if (! Craft::$app->getElements()->saveElement($asset)) {
                            $errors = $asset->getFirstErrors();

                            return $this->asFailure(Craft::t('app', 'Failed to save the asset:') . ' ' . implode(";\n", $errors));
                        }

                        $totalWordCount = Translations::$plugin->fileRepository->getUploadedFilesWordCount($asset, $file->extension);

                        // Process files via job or directly based on file "size"
                        if ($totalWordCount > Constants::WORD_COUNT_LIMIT) {
                            $job = Craft::$app->queue->push(new ImportFiles([
                                'description' => Constants::JOB_IMPORTING_FILES,
                                'orderId' => $orderId,
                                'totalFiles' => $total_files,
                                'assets' => [$asset->id],
                                'fileFormat' => $file->extension,
                                'fileNames' => [$asset->id => $file->name],
                                'discardElements' => $sourceChangedElements
                            ]));

                            if ($job) {
                                $queueOrders = Craft::$app->getSession()->get('queueOrders');
                                $queueOrders[$job] = $orderId;
                                Craft::$app->getSession()->set('queueOrders', $queueOrders);
                                Craft::$app->getSession()->set('importQueued', "1");
                                $params = [
                                    'id' => (int) $job,
                                    'notice' => 'Done updating translation drafts',
                                    'url' => Constants::URL_ORDER_DETAIL . $orderId
                                ];
                                Craft::$app->getView()->registerJs('$(function(){ Craft.Translations.trackJobProgressById(true, false, '. json_encode($params) .'); });');
                            }
                            $this->showUserMessages("File: {$file->name} queued for import. Check activity log for any errors.", true);
                        } else {
                            $fileSvc = new ImportFiles();
                            $a = Craft::$app->getAssets()->getAssetById($asset->id);
                            $res = $fileSvc->processFile($a, $this->order, $file->extension, [$asset->id => $file->name], $sourceChangedElements);
                            Craft::$app->getElements()->deleteElement($a);

                            if($res !== false){
                                $this->showUserMessages("File uploaded successfully: {$file->name}", true);
                            } else {
                                Craft::$app->getSession()->set('fileImportError', 1);
                                $this->showUserMessages("File import error. Please check the order activity log for details.");
                            }
                        }
                    }
                }
            } else {
                Craft::$app->getSession()->set('fileImportError', 1);
                $this->showUserMessages("The file you are trying to import is empty.");
            }
        } catch (\Exception $exception) {
            $this->showUserMessages($exception->getMessage());
        }
    }

    /**
     * Get Difference in File source and target
     *
     * @return void
     */
    public function actionGetFileDiff()
    {
        $success = false;
        $error = null;
        $data = ['previewClass' => 'disabled', 'originalUrl' => '', 'newUrl' => ''];

        $fileId = Craft::$app->getRequest()->getParam('fileId');
        if (!$fileId) {
            $error = "FileId not found.";
        } else {
            $file = Translations::$plugin->fileRepository->getFileById($fileId);
            $error = "File not found.";
            if ($file && (in_array($file->status, [Constants::FILE_STATUS_REVIEW_READY, Constants::FILE_STATUS_COMPLETE, Constants::FILE_STATUS_PUBLISHED]))) {
                try {
                    $element = Craft::$app->getElements()->getElementById($file->elementId, null, $file->sourceSite);

                    $data['diff'] = Translations::$plugin->fileRepository->getSourceTargetDifferences($file->source, $file->target);

                    if ($file->status !== Constants::FILE_STATUS_REVIEW_READY) {
                        $data['previewClass'] = '';
                        $data['originalUrl'] = $element->url;
                        $data['newUrl'] = $file->previewUrl;
                    }
                    $data['fileId'] = $file->id;

                    $error = null;
                    $success = true;
                } catch(\Exception $e) {
                    $error = $e->getMessage();
                }
            }
        }

        return $this->asJson([
            'success' => $success,
            'data' => $data,
            'error' => $error
        ]);
    }

    /**
     * Create Zip of Translations memory alignment files
     */
    public function actionCreateTmExportZip() {
        $orderId = Craft::$app->getRequest()->getBodyParam('orderId');
        $files = json_decode(Craft::$app->getRequest()->getBodyParam('files'), true);

        try {
            $order = Translations::$plugin->orderRepository->getOrderById($orderId);
            $orderAttributes = $order->getAttributes();

            //Filename Zip Folder
            $zipName = $this->getZipName($orderAttributes) . '_TM';

            // Set destination zip
            $zipDest = Craft::$app->path->getTempPath() . '/' . $zipName . '.' . Constants::FILE_FORMAT_ZIP;

            // Create zip
            $zip = new \ZipArchive();

            // Open zip
            if ($zip->open($zipDest, $zip::CREATE) !== true) {
                throw new \Exception('Unable to create zip file: '.$zipDest);
            }

            //Iterate over each file on this order
            if ($order->files) {
                foreach ($order->GetFiles() as $file) {
                    if (! in_array($file->id, $files) || !$file->hasTmMissAlignments()) continue;

                    $tmFile = $file->getTmMissAlignmentFile();
                    $fileName = $tmFile['fileName'];
                    $fileContent = $tmFile['fileContent'];

                    if (! $fileContent || ! $zip->addFromString($fileName, $fileContent)) {
                        throw new \Exception('There was an error adding the file '.$fileName.' to the zip: '.$zipName);
                    }

                    $file->reference = $tmFile['fileContent'];
                    Translations::$plugin->fileRepository->saveFile($file);
                }
            }

            // Close zip
            $zip->close();
        } catch(\Exception $e) {
            Craft::error('['. __METHOD__ .']' . $e->getMessage(), 'translations');
            return $this->asJson(['success' => false, 'message' => $e->getMessage()]);
        }

        return $this->asJson(['success' => true, 'tmFiles' => $zipDest]);
    }

    /**
     * Send Translation memory files to translation service provider
     */
    public function actionSyncTmFiles() {
        $orderId = Craft::$app->getRequest()->getBodyParam('orderId');
        $files = json_decode(Craft::$app->getRequest()->getBodyParam('files'), true);
        $order = Translations::$plugin->orderRepository->getOrderById($orderId);

        //Iterate over each file on this order
        if ($order->files) {
            foreach ($order->GetFiles() as $file) {
                if (in_array($file->id, $files) && $file->hasTmMissAlignments()) {
                    $translationService = Translations::$plugin->translatorFactory->makeTranslationService(
                        $order->getTranslator()->service,
                        $order->getTranslator()->getSettings()
                    );

                    $translationService->sendOrderReferenceFile($order, $file);
                }
            }
        }
        return $this->asJson(['success' => true]);
    }

    // Private Methods
	/**
     * Show Flash Notifications and Errors to the translator
	 */
    private function showUserMessages($message, $isSuccess = false)
    {
    	if ($isSuccess) {
			Craft::$app->session->setNotice(Craft::t('app', $message));
    	} else {
    		Craft::$app->session->setError(Craft::t('app', $message));
    	}
    }

    /**
     * @param $order
     * @return string
     */
    private function getZipName($order) {

        $title = str_replace(' ', '_', $order['title']);
        $title = preg_replace('/[^A-Za-z0-9\-_]/', '', $title);
        $len = 50;
        $title = (strlen($title) > $len) ? substr($title,0,$len) : $title;
        $zip_name =  $title.'_'.$order['id'];

        return $zip_name;
    }
}
