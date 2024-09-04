<?php

namespace acclaro\translations\migrations;

use acclaro\translations\Constants;
use craft\db\Migration;

/**
 * Migration to update translations_activitylogs table
 */
class m240904_153700_update_element_site_titles extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        // Check if the table exists
        $this->db->execute("update elements_sites, content, translations_orders
            set elements_sites.title = content.title
            where elements_sites.elementId = content.elementId
            and content.elementId = translations_orders.id");
        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m240904_153700_update_element_site_titles cannot be reverted.\n";
        return false;
    }
}