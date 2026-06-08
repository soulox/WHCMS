<?php

class MakeProxmoxWhmcsStore
{
    public static function getHostingById($serviceId)
    {
        $row = self::table('tblhosting')->where('id', (int) $serviceId)->first();
        if (!$row) {
            return null;
        }

        return (array) $row;
    }

    public static function getServerById($serverId)
    {
        $row = self::table('tblservers')->where('id', (int) $serverId)->first();
        if (!$row) {
            return null;
        }

        return (array) $row;
    }

    public static function getCustomFieldValue($serviceId, $productId, $fieldName)
    {
        $fieldId = self::findCustomFieldId($productId, $fieldName);
        if (!$fieldId) {
            return '';
        }

        $value = self::table('tblcustomfieldsvalues')
            ->where('relid', (int) $serviceId)
            ->where('fieldid', (int) $fieldId)
            ->value('value');

        return (string) $value;
    }

    public static function setCustomFieldValue($serviceId, $productId, $fieldName, $value)
    {
        $fieldId = self::findCustomFieldId($productId, $fieldName);
        if (!$fieldId) {
            return false;
        }

        $query = self::table('tblcustomfieldsvalues')
            ->where('relid', (int) $serviceId)
            ->where('fieldid', (int) $fieldId);

        if ($query->first()) {
            $query->update(array('value' => (string) $value));
            return true;
        }

        self::table('tblcustomfieldsvalues')->insert(array(
            'relid' => (int) $serviceId,
            'fieldid' => (int) $fieldId,
            'value' => (string) $value,
        ));

        return true;
    }

    public static function updateHosting($serviceId, array $changes)
    {
        if (empty($changes)) {
            return;
        }

        self::table('tblhosting')->where('id', (int) $serviceId)->update($changes);
    }

    private static function findCustomFieldId($productId, $fieldName)
    {
        $field = self::table('tblcustomfields')
            ->where('type', 'product')
            ->where('relid', (int) $productId)
            ->where('fieldname', 'like', $fieldName . '%')
            ->orderBy('id', 'asc')
            ->first();

        return $field ? (int) $field->id : 0;
    }

    private static function table($name)
    {
        $capsule = '\\WHMCS\\Database\\Capsule';
        if (!class_exists($capsule)) {
            throw new Exception('WHMCS Capsule class not found.');
        }

        return $capsule::table($name);
    }
}
