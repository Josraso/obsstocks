<?php
/**
 * OBS Stocks - lectura de datos (stock, avisos) para exportaciones, cron y pantalla.
 *
 * Mismas columnas que la versión 1.x. Las consultas se hacen en bloque (antes se cargaba un
 * objeto Product por cada fila y con miles de productos era muy lento).
 *
 * @author    OBSolutions.es / adaptación PrestaShop 9
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class Mystocks
{
    const TABLE = 'obsstocks_minstocks';

    /* ------------------------------------------------------------------
     * Exportaciones (primera fila = cabecera)
     * ------------------------------------------------------------------ */

    /**
     * Productos/combinaciones con aviso configurado cuyo stock está por debajo del mínimo.
     * REFERENCE;NAME;CURRENT STOCK;MIN STOCK;NAME EN;QTY
     */
    public static function getDatasheetStockBelowMinium()
    {
        $out = [['REFERENCE', 'NAME', 'CURRENT STOCK', 'MIN STOCK', 'NAME EN', 'QTY']];
        foreach (self::belowMinimum() as $r) {
            $out[] = [self::xref($r['reference']), $r['name'], (int) $r['stock'], (int) $r['min_stock'], $r['name_en'], (int) $r['qty']];
        }

        return $out;
    }

    /**
     * Todo el stock: combinaciones y productos sin combinaciones.
     * TYPE;OBJECT_ID;REFERENCE;NAME;CURRENT STOCK
     */
    public static function getDatasheetAllStock()
    {
        $out = [['TYPE', 'OBJECT_ID', 'REFERENCE', 'NAME', 'CURRENT STOCK']];
        foreach (self::catalog(false) as $r) {
            $out[] = [$r['type'], (int) $r['id'], self::xref($r['reference']), $r['name'], (int) $r['stock']];
        }

        return $out;
    }

    /**
     * Avisos configurados (solo los que tienen mínimo > 0).
     * REFERENCE;NAME;MIN ALERT STOCK;QTY
     */
    public static function getDatasheetCurrentAlertConf()
    {
        $alerts = self::alerts();
        $out = [['REFERENCE', 'NAME', 'MIN ALERT STOCK', 'QTY']];
        foreach (self::catalog(false) as $r) {
            // Como en la versión anterior: combinaciones solo de productos activos
            if ($r['type'] == 'combination' && !$r['active']) {
                continue;
            }
            $ref = trim((string) $r['reference']);
            if ($ref === '' || !isset($alerts[$ref]) || (int) $alerts[$ref]['min_stock'] <= 0) {
                continue;
            }
            $out[] = [self::xref($ref), $r['name'], (int) $alerts[$ref]['min_stock'], (int) $alerts[$ref]['qty']];
        }

        return $out;
    }

    /**
     * NUEVO: stock y avisos de todos los productos en un solo archivo, listo para editar y volver a subir.
     * TYPE;OBJECT_ID;REFERENCE;NAME;CURRENT STOCK;MIN STOCK;QTY
     */
    public static function getDatasheetStockAndAlerts()
    {
        $alerts = self::alerts();
        $out = [['TYPE', 'OBJECT_ID', 'REFERENCE', 'NAME', 'CURRENT STOCK', 'MIN STOCK', 'QTY']];
        foreach (self::catalog(false) as $r) {
            $ref = trim((string) $r['reference']);
            $a = ($ref !== '' && isset($alerts[$ref])) ? $alerts[$ref] : ['min_stock' => 0, 'qty' => 0];
            $out[] = [$r['type'], (int) $r['id'], self::xref($ref), $r['name'], (int) $r['stock'], (int) $a['min_stock'], (int) $a['qty']];
        }

        return $out;
    }

    /** Ejemplo para la plantilla vacía */
    public static function getDatasheetExample()
    {
        return [
            ['REFERENCE', 'NAME', 'CURRENT STOCK', 'MIN STOCK', 'QTY'],
            ['005038B', 'Ejemplo: solo se usa la referencia, el nombre es orientativo', 12, 5, 20],
            ['072005B', 'Si dejas una celda vacía, ese dato no se cambia', '', 10, 30],
        ];
    }

    /* ------------------------------------------------------------------
     * Datos
     * ------------------------------------------------------------------ */

    /** Avisos: [reference => ['min_stock' =>, 'qty' =>]] */
    public static function alerts()
    {
        $out = [];
        $rows = Db::getInstance()->executeS('SELECT `reference`, `min_stock`, `qty` FROM `' . _DB_PREFIX_ . self::TABLE . '`');
        foreach ((array) $rows as $r) {
            $out[trim((string) $r['reference'])] = ['min_stock' => (int) $r['min_stock'], 'qty' => (int) $r['qty']];
        }

        return $out;
    }

    /** Guarda (crea o cambia) el aviso de una referencia */
    public static function saveAlert($reference, $min, $qty)
    {
        return (bool) Db::getInstance()->execute(
            'INSERT INTO `' . _DB_PREFIX_ . self::TABLE . '` (`reference`, `min_stock`, `qty`)
            VALUES (\'' . pSQL($reference) . '\', ' . (int) $min . ', ' . (int) $qty . ')
            ON DUPLICATE KEY UPDATE `min_stock` = ' . (int) $min . ', `qty` = ' . (int) $qty
        );
    }

    /** Quita el aviso de una referencia */
    public static function deleteAlert($reference)
    {
        return (bool) Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . self::TABLE . '` WHERE `reference` = \'' . pSQL($reference) . '\''
        );
    }

    /** Carpeta de las copias de avisos (protegida por el .htaccess de logs/) */
    public static function backupDir()
    {
        return dirname(dirname(__FILE__)) . '/logs/';
    }

    /**
     * Copia de todos los avisos en un CSV que se puede volver a subir tal cual
     * (REFERENCE;MIN STOCK;QTY). Se conservan las 15 últimas.
     *
     * @return string nombre del archivo, o '' si no se pudo guardar
     */
    public static function backupAlerts()
    {
        $rows = [['REFERENCE', 'MIN STOCK', 'QTY']];
        foreach (self::alerts() as $ref => $a) {
            $rows[] = [self::xref($ref), (int) $a['min_stock'], (int) $a['qty']];
        }
        $name = 'avisos-' . date('Ymd-His') . '.csv';
        if (@file_put_contents(self::backupDir() . $name, self::toCsv($rows)) === false) {
            return '';
        }
        $all = self::backups();
        foreach (array_slice($all, 15) as $old) {
            @unlink(self::backupDir() . $old);
        }

        return $name;
    }

    /** Copias guardadas, de la más reciente a la más antigua */
    public static function backups()
    {
        $out = [];
        foreach ((array) glob(self::backupDir() . 'avisos-*.csv') as $f) {
            if ($f && preg_match('/^avisos-[0-9]{8}-[0-9]{6}\.csv$/', basename($f))) {
                $out[] = basename($f);
            }
        }
        rsort($out);

        return $out;
    }

    /**
     * Catálogo: combinaciones y productos sin combinaciones, con nombre y stock.
     *
     * @return array filas [type, id, id_product, id_product_attribute, reference, name, stock, active]
     */
    public static function catalog($active_only = false, $id_lang = null)
    {
        $db = Db::getInstance();
        $id_lang = $id_lang ? (int) $id_lang : (int) Configuration::get('PS_LANG_DEFAULT');
        $id_shop = (int) Context::getContext()->shop->id;
        $p = _DB_PREFIX_;

        $stock = self::stockMap();
        $names = self::productNames($id_lang);
        $out = [];

        $combis = $db->executeS(
            'SELECT pa.`id_product_attribute`, pa.`id_product`, pa.`reference`, ps.`active`
            FROM `' . $p . 'product_attribute` pa
            INNER JOIN `' . $p . 'product` p ON (p.`id_product` = pa.`id_product`)
            INNER JOIN `' . $p . 'product_shop` ps ON (ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $id_shop . ')'
            . ($active_only ? ' WHERE ps.`active` = 1' : '') . '
            ORDER BY pa.`id_product`, pa.`id_product_attribute`'
        );
        $attr = self::attributeNames(array_column((array) $combis, 'id_product_attribute'), $id_lang);
        $with_combis = [];
        foreach ((array) $combis as $c) {
            $ipa = (int) $c['id_product_attribute'];
            $ip = (int) $c['id_product'];
            $with_combis[$ip] = true;
            $name = isset($names[$ip]) ? $names[$ip] : '';
            if (!empty($attr[$ipa])) {
                $name .= ':' . $attr[$ipa];
            }
            $out[] = [
                'type' => 'combination', 'id' => $ipa, 'id_product' => $ip, 'id_product_attribute' => $ipa,
                'reference' => (string) $c['reference'], 'name' => $name,
                'stock' => isset($stock[$ip . '-' . $ipa]) ? $stock[$ip . '-' . $ipa] : 0, 'active' => (int) $c['active'],
            ];
        }

        $prods = $db->executeS(
            'SELECT p.`id_product`, p.`reference`, ps.`active`
            FROM `' . $p . 'product` p
            INNER JOIN `' . $p . 'product_shop` ps ON (ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $id_shop . ')'
            . ($active_only ? ' WHERE ps.`active` = 1' : '') . '
            ORDER BY p.`id_product`'
        );
        foreach ((array) $prods as $r) {
            $ip = (int) $r['id_product'];
            if (isset($with_combis[$ip])) {
                continue;
            }
            $out[] = [
                'type' => 'product', 'id' => $ip, 'id_product' => $ip, 'id_product_attribute' => 0,
                'reference' => (string) $r['reference'], 'name' => isset($names[$ip]) ? $names[$ip] : '',
                'stock' => isset($stock[$ip . '-0']) ? $stock[$ip . '-0'] : 0, 'active' => (int) $r['active'],
            ];
        }

        return $out;
    }

    /**
     * Stock por debajo del mínimo (misma regla que la versión 1.x: producto activo, mínimo > 0
     * y stock < mínimo; la referencia se busca primero en combinaciones y luego en productos).
     */
    public static function belowMinimum()
    {
        $alerts = self::alerts();
        if (!$alerts) {
            return [];
        }
        $id_lang = (int) Configuration::get('PS_LANG_DEFAULT');
        $id_en = (int) Language::getIdByIso('en');
        $catalog = self::catalog(false, $id_lang);
        $en = [];
        if ($id_en && $id_en != $id_lang) {
            foreach (self::catalog(false, $id_en) as $r) {
                $en[$r['type'] . $r['id']] = $r['name'];
            }
        }

        // Primera coincidencia por referencia: combinaciones antes que productos
        $byRef = [];
        foreach ($catalog as $r) {
            $ref = trim($r['reference']);
            if ($ref !== '' && $r['type'] == 'combination' && !isset($byRef[$ref])) {
                $byRef[$ref] = $r;
            }
        }
        foreach ($catalog as $r) {
            $ref = trim($r['reference']);
            if ($ref !== '' && !isset($byRef[$ref])) {
                $byRef[$ref] = $r;
            }
        }
        $out = [];
        foreach ($alerts as $ref => $a) {
            if ((int) $a['min_stock'] <= 0 || !isset($byRef[$ref])) {
                continue;
            }
            $r = $byRef[$ref];
            if (!$r['active'] || (int) $r['stock'] >= (int) $a['min_stock']) {
                continue;
            }
            $out[] = [
                'reference' => $ref,
                'type' => $r['type'],
                'id_product' => $r['id_product'],
                'name' => $r['name'],
                'name_en' => isset($en[$r['type'] . $r['id']]) ? $en[$r['type'] . $r['id']] : $r['name'],
                'stock' => (int) $r['stock'],
                'min_stock' => (int) $a['min_stock'],
                'qty' => (int) $a['qty'],
            ];
        }

        return $out;
    }

    /** Stock disponible: ["id_product-id_product_attribute" => cantidad] en la tienda actual */
    public static function stockMap()
    {
        $out = [];
        $rows = Db::getInstance()->executeS(
            'SELECT sa.`id_product`, sa.`id_product_attribute`, sa.`quantity`
            FROM `' . _DB_PREFIX_ . 'stock_available` sa
            WHERE 1 ' . StockAvailable::addSqlShopRestriction(null, null, 'sa')
        );
        foreach ((array) $rows as $r) {
            $out[(int) $r['id_product'] . '-' . (int) $r['id_product_attribute']] = (int) $r['quantity'];
        }

        return $out;
    }

    private static function productNames($id_lang)
    {
        $out = [];
        $rows = Db::getInstance()->executeS(
            'SELECT `id_product`, `name` FROM `' . _DB_PREFIX_ . 'product_lang`
            WHERE `id_lang` = ' . (int) $id_lang . ' AND `id_shop` = ' . (int) Context::getContext()->shop->id
        );
        foreach ((array) $rows as $r) {
            $out[(int) $r['id_product']] = (string) $r['name'];
        }

        return $out;
    }

    private static function attributeNames(array $ids, $id_lang)
    {
        $out = [];
        $ids = array_filter(array_map('intval', $ids));
        foreach (array_chunk($ids, 1000) as $chunk) {
            $rows = Db::getInstance()->executeS(
                'SELECT pac.`id_product_attribute`, GROUP_CONCAT(al.`name` ORDER BY ag.`position`, a.`position` SEPARATOR \':\') AS names
                FROM `' . _DB_PREFIX_ . 'product_attribute_combination` pac
                INNER JOIN `' . _DB_PREFIX_ . 'attribute` a ON (a.`id_attribute` = pac.`id_attribute`)
                INNER JOIN `' . _DB_PREFIX_ . 'attribute_group` ag ON (ag.`id_attribute_group` = a.`id_attribute_group`)
                INNER JOIN `' . _DB_PREFIX_ . 'attribute_lang` al ON (al.`id_attribute` = a.`id_attribute` AND al.`id_lang` = ' . (int) $id_lang . ')
                WHERE pac.`id_product_attribute` IN (' . implode(',', $chunk) . ')
                GROUP BY pac.`id_product_attribute`'
            );
            foreach ((array) $rows as $r) {
                $out[(int) $r['id_product_attribute']] = (string) $r['names'];
            }
        }

        return $out;
    }

    /* ------------------------------------------------------------------
     * Búsquedas por referencia (para la importación)
     * ------------------------------------------------------------------ */

    /** @return array filas [id_product, id_product_attribute] */
    public static function combinationsByReference($reference)
    {
        return (array) Db::getInstance()->executeS(
            'SELECT `id_product`, `id_product_attribute` FROM `' . _DB_PREFIX_ . 'product_attribute`
            WHERE TRIM(`reference`) = \'' . pSQL(trim($reference)) . '\''
        );
    }

    /** @return array filas [id_product, combis] */
    public static function productsByReference($reference)
    {
        return (array) Db::getInstance()->executeS(
            'SELECT p.`id_product`, (SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product_attribute` pa WHERE pa.`id_product` = p.`id_product`) AS combis
            FROM `' . _DB_PREFIX_ . 'product` p WHERE TRIM(p.`reference`) = \'' . pSQL(trim($reference)) . '\''
        );
    }

    /* Compatibilidad con la versión 1.x */
    public static function getProductByReference($reference)
    {
        $r = self::productsByReference($reference);

        return $r ? new Product((int) $r[0]['id_product']) : false;
    }

    public static function getCombinationByReference($reference)
    {
        $r = self::combinationsByReference($reference);

        return $r ? new Combination((int) $r[0]['id_product_attribute']) : false;
    }

    /* ------------------------------------------------------------------
     * CSV
     * ------------------------------------------------------------------ */

    /** Referencia como ="REF" para que Excel no quite los ceros a la izquierda (como antes) */
    public static function xref($ref)
    {
        $ref = (string) $ref;
        if ($ref === '' || strpbrk($ref, "\";\r\n") !== false) {
            return $ref;
        }

        return '="' . $ref . '"';
    }

    /** Texto CSV separado por ; con BOM UTF-8 (para que Excel muestre bien los acentos) */
    public static function toCsv(array $rows)
    {
        $lines = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row as $v) {
                $v = (string) $v;
                if (strpos($v, '="') === 0) {
                    $cells[] = $v;
                } elseif ($v !== '' && strpbrk($v, "\";\r\n") !== false) {
                    $cells[] = '"' . str_replace('"', '""', str_replace(["\r", "\n"], ' ', $v)) . '"';
                } else {
                    $cells[] = $v;
                }
            }
            $lines[] = implode(';', $cells);
        }

        return "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
    }
}
