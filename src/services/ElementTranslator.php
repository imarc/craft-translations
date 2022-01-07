<?php
/**
 * Translations for Craft plugin for Craft CMS 3.x
 *
 * Translations for Craft eliminates error prone and costly copy/paste workflows for launching human translated Craft CMS web content.
 *
 * @link      http://www.acclaro.com/
 * @copyright Copyright (c) 2018 Acclaro
 */

namespace acclaro\translations\services;

use Craft;
use DOMDocument;
use craft\base\Field;
use craft\base\Element;
use craft\elements\Tag;
use craft\elements\Entry;
use craft\elements\Category;
use acclaro\translations\Constants;
use acclaro\translations\Translations;

class ElementTranslator
{
    public function toTranslationSource(Element $element, $sourceSite=null)
    {
        $source = array();
        
        // if ($element instanceof Element || $element instanceof Tag || $element instanceof Category) {
        if ($element instanceof Element) {
            if ($element->title) {
                $source['title'] = $element->title;
            }
            if ($element->slug) {
                $source['slug'] = $element->slug;
            }

        }

        foreach ($element->getFieldLayout()->getFields() as $layoutField) {
            $field = Craft::$app->fields->getFieldById($layoutField->id);
            $fieldSource = $this->fieldToTranslationSource($element, $field, $sourceSite);

            $source = array_merge($source, $fieldSource);
        }
        
        return $source;
    }

    private function getDataFormat($data) {
        if (strpos($data, "<xml>") !== false) {
            return Constants::FILE_FORMAT_XML;
        }
        return Constants::FILE_FORMAT_JSON;
    }

    public function getTargetData($content, $nonNested = false) {
        if ($this->getDataFormat($content) === Constants::FILE_FORMAT_XML) {
            return $this->getTargetDataFromXml($content, $nonNested);
        } else {
            $targetData = [];
            $content = json_decode($content, true);

            if ($nonNested) {
                return $content['content'];
            }

            foreach ($content['content'] as $name => $value) {
                if (strpos($name, '.') !== false) {
                    $parts = explode('.', $name);
                    $container =& $targetData;

                    while ($parts) {
                        $key = array_shift($parts);
    
                        if (!isset($container[$key])) {
                            $container[$key] = array();
                        }
    
                        $container =& $container[$key];
                    }
    
                    $container = $value;
                } else {
                    $targetData[$name] = $value;
                }
            }

            return $targetData;
        }
    }

    public function getTargetDataFromXml($xml, $nonNested = false)
    {
        $dom = new DOMDocument('1.0', 'utf-8');

        $dom->loadXML($xml);

        $targetData = array();

        $contents = $dom->getElementsByTagName('content');

        
        foreach ($contents as $content) {
            $name = (string) $content->getAttribute('resname');
            $value = (string) $content->nodeValue;

            if ($nonNested) {
                $targetData[$name] = $value;
                continue;
            }
            
            if (strpos($name, '.') !== false) {
                $parts = explode('.', $name);
                $container =& $targetData;

                while ($parts) {
                    $key = array_shift($parts);

                    if (!isset($container[$key])) {
                        $container[$key] = array();
                    }

                    $container =& $container[$key];
                }

                $container = $value;
            } else {
                $targetData[$name] = $value;
            }
        }

        return $targetData;
    }

    public function toPostArrayFromTranslationTarget(Element $element, $sourceSite, $targetSite, $targetData, $includeNonTranslatable = false)
    {
        $post = array();

        foreach($element->getFieldLayout()->getFields() as $key => $layoutField) {
            $field = Craft::$app->fields->getFieldById($layoutField->id);
            $fieldHandle = $field->handle;
            
            $fieldType = $field;

            $translator = Translations::$plugin->fieldTranslatorFactory->makeTranslator($fieldType);

            if (!$translator) {
                if ($includeNonTranslatable) {
                    $post[$fieldHandle] = $element->$fieldHandle;
                }

                continue;
            }

            $fieldPost = [];
            if (isset($targetData[$fieldHandle])) {
                    $fieldPost = $translator->toPostArrayFromTranslationTarget($this, $element, $field, $sourceSite, $targetSite, $targetData[$fieldHandle]);
            } else {
                $fieldPost = $translator->toPostArray($this, $element, $field, $sourceSite);
            }

            if (!is_array($fieldPost)) {
                $fieldPost = array($fieldHandle => $fieldPost);
            }

            $post = array_merge($post, $fieldPost);
        }

        return $post;
    }

    public function toPostArray(Element $element)
    {
        $source = array();

        foreach($element->getFieldLayout()->getFields() as $layoutField) {
            $field = Craft::$app->fields->getFieldById($layoutField->id);

            $fieldSource = $this->fieldToPostArray($element, $field);

            $source = array_merge($source, $fieldSource);
        }

        return $source;
    }

    public function getWordCount(Element $element)
    {
        $wordCount = 0;

        if ($element instanceof Entry || $element instanceof Tag || $element instanceof Category) {
            $wordCount += Translations::$plugin->wordCounter->getWordCount($element->title);
            $wordCount += Translations::$plugin->wordCounter->getWordCount($element->slug);
        }
        foreach($element->getFieldLayout()->getFields() as $layoutField) {
            $field = Craft::$app->fields->getFieldById($layoutField->id);

            $wordCount += $this->getFieldWordCount($element, $field);
        }

        return $wordCount;
    }

    public function fieldToTranslationSource(Element $element, Field $field, $sourceSite=null)
    {
        $fieldType = $field;

        $translator = Translations::$plugin->fieldTranslatorFactory->makeTranslator($fieldType);

        $fieldSource = array();

        // Check if field is translatable or is nested field
        if ($translator && $field->getIsTranslatable() || $translator && in_array(get_class($field), Constants::NESTED_FIELD_TYPES)) {
            $fieldSource = $translator->toTranslationSource($this, $element, $field, $sourceSite);

            if (!is_array($fieldSource)) {
                $fieldSource = array($field->handle => $fieldSource);
            }
        }

        return $fieldSource;
    }

    public function fieldToPostArray(Element $element, Field $field)
    {
        $fieldType = $field;

        $fieldHandle = $field->handle;

        $translator = Translations::$plugin->fieldTranslatorFactory->makeTranslator($fieldType);

        $fieldSource = array();

        // Check if field is translatable or is nested field
        if ($translator && $field->getIsTranslatable() || $translator && in_array(get_class($field), Constants::NESTED_FIELD_TYPES)) {
            $fieldSource = $translator->toPostArray($this, $element, $field);

            if (!is_array($fieldSource)) {
                $fieldSource = array($fieldHandle => $fieldSource);
            }
        }

        return $fieldSource;
    }

    public function getFieldWordCount(Element $element, Field $field)
    {
        $fieldType = $field;

        $fieldHandle = $field->handle;

        // Check if field is translatable or is nested field
        if ($field->getIsTranslatable() || in_array(get_class($field), Constants::NESTED_FIELD_TYPES)) {
            $translator = Translations::$plugin->fieldTranslatorFactory->makeTranslator($fieldType);

            return $translator ? $translator->getWordCount($this, $element, $field) : 0;
        }
    }
}
