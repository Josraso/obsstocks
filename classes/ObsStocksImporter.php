<?php
/**
 * OBS Stocks - importación de CSV (stock real, stock mínimo de aviso y cantidad a pedir)
 *
 * Acepta los mismos archivos que la versión 1.x y además:
 *  - el archivo de "todo el stock" (TYPE;OBJECT_ID;...) y el nuevo "stock y avisos",
 *  - columnas en cualquier orden y con nombres en español o inglés,
 *  - separador ; , o tabulador, archivos guardados por Excel (con BOM o en Windows-1252),
 *  - referencias en formato ="REF".
 * Siempre devuelve un informe de lo que ha hecho (o haría, en modo comprobación).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/Mystocks.php';

class ObsStocksImporter
{
    /** Nombres de columna admitidos (normalizados: mayúsculas, sin acentos) */
    public static $columns = [
        'reference' => ['REFERENCE', 'REFERENCIA', 'REF', 'SKU'],
        'type' => ['TYPE', 'TIPO'],
        'id' => ['OBJECT_ID', 'ID'],
        'stock' => ['CURRENT STOCK', 'REAL STOCK', 'STOCK', 'STOCK ACTUAL', 'STOCK REAL', 'CANTIDAD', 'QUANTITY', 'EXISTENCIAS'],
        'min' => ['MIN STOCK', 'MIN ALERT STOCK', 'ALERT STOCK', 'STOCK MINIMO', 'MINIMO', 'STOCK MIN', 'AVISO', 'ALERTA'],
        'qty' => ['QTY', 'CANTIDAD A PEDIR', 'A PEDIR', 'PEDIR', 'REPONER', 'CANTIDAD PEDIDO'],
    ];

    public $update_stock = true;
    public $update_alerts = true;
    public $add_stock = false;
    public $dry = true;

    /** @var array informe */
    public $report;

    public function __construct($update_stock, $update_alerts, $add_stock, $dry)
    {
        $this->update_stock = (bool) $update_stock;
        $this->update_alerts = (bool) $update_alerts;
        $this->add_stock = (bool) $add_stock;
        $this->dry = (bool) $dry;
    }

    /* ------------------------------------------------------------------
     * Lectura
     * ------------------------------------------------------------------ */

    public static function normalize($s)
    {
        $s = preg_replace('/^\xEF\xBB\xBF/', '', (string) $s);
        $s = self::clean($s);
        $s = strtr(Tools::strtoupper($s), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N', 'À' => 'A', 'È' => 'E', 'Ò' => 'O', 'Ç' => 'C', 'Ï' => 'I']);
        $s = preg_replace('/[\s_\-\.:]+/', ' ', $s);
        $s = trim($s);

        return $s === 'OBJECT ID' ? 'OBJECT_ID' : $s;
    }

    /** Quita ="..." y comillas sobrantes */
    public static function clean($v)
    {
        $v = trim((string) $v);
        if (preg_match('/^="(.*)"$/s', $v, $m)) {
            $v = $m[1];
        } elseif (strlen($v) > 1 && $v[0] === '"' && substr($v, -1) === '"') {
            $v = substr($v, 1, -1);
        }

        return trim(str_replace("\xC2\xA0", ' ', $v));
    }

    /**
     * Lee el archivo y detecta columnas.
     *
     * @return array ['map' => [campo => índice], 'rows' => [[...]], 'delimiter' => ';', 'header' => [...], 'error' => '']
     */
    public static function read($path)
    {
        $res = ['map' => [], 'rows' => [], 'delimiter' => ';', 'header' => [], 'error' => ''];
        $content = (string) Tools::file_get_contents($path);
        if (trim($content) === '') {
            $res['error'] = 'El archivo está vacío.';

            return $res;
        }
        // Excel en Windows guarda en ANSI (Windows-1252): se pasa a UTF-8
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        if (function_exists('mb_check_encoding') && !mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $lines = explode("\n", $content);
        $first = '';
        foreach ($lines as $l) {
            if (trim($l) !== '') {
                $first = $l;
                break;
            }
        }
        $counts = [';' => substr_count($first, ';'), ',' => substr_count($first, ','), "\t" => substr_count($first, "\t")];
        arsort($counts);
        $d = key($counts);
        if (!$counts[$d]) {
            $d = ';';
        }
        $res['delimiter'] = $d;

        $fh = fopen('php://memory', 'r+');
        fwrite($fh, $content);
        rewind($fh);
        $header = null;
        while (($row = fgetcsv($fh, 0, $d, '"', '\\')) !== false) {
            if ($row === [null] || trim(implode('', $row)) === '') {
                continue;
            }
            if ($header === null) {
                $header = $row;
                continue;
            }
            $res['rows'][] = $row;
        }
        fclose($fh);

        $res['header'] = (array) $header;
        foreach ((array) $header as $i => $h) {
            $n = self::normalize($h);
            foreach (self::$columns as $field => $names) {
                if (!isset($res['map'][$field]) && in_array($n, $names, true)) {
                    $res['map'][$field] = $i;
                }
            }
        }
        // Formato 1.x "referencia;nombre;cantidad": cantidad = stock real
        if (!isset($res['map']['reference']) && !isset($res['map']['id'])) {
            $res['error'] = 'No se encuentra la columna REFERENCE (o REFERENCIA) en la primera fila. La primera fila tiene que ser la cabecera con los nombres de las columnas.';
        } elseif (!isset($res['map']['stock']) && !isset($res['map']['min']) && !isset($res['map']['qty'])) {
            $res['error'] = 'No hay ninguna columna que actualizar. Tiene que haber al menos una de: CURRENT STOCK (stock real), MIN STOCK (stock mínimo de aviso) o QTY (cantidad a pedir).';
        }

        return $res;
    }

    private static function num($v, &$ok)
    {
        $v = str_replace([' ', "\xC2\xA0"], '', self::clean($v));
        if ($v === '') {
            $ok = true;

            return null;
        }
        $v = str_replace(',', '.', $v);
        if (!is_numeric($v)) {
            $ok = false;

            return null;
        }
        $ok = true;

        return (int) round((float) $v);
    }

    /* ------------------------------------------------------------------
     * Proceso
     * ------------------------------------------------------------------ */

    public function run($path)
    {
        $data = self::read($path);
        $this->report = [
            'error' => $data['error'],
            'header' => $data['header'],
            'map' => $data['map'],
            'delimiter' => $data['delimiter'],
            'rows' => 0,
            'stock_changed' => 0, 'alert_changed' => 0, 'unchanged' => 0, 'not_found' => 0, 'invalid' => 0,
            'lines' => [],
        ];
        if ($data['error']) {
            return $this->report;
        }

        $map = $data['map'];
        $alerts = Mystocks::alerts();
        $stockMap = Mystocks::stockMap();
        $useIds = isset($map['id']) && isset($map['type']);
        $doStock = $this->update_stock && isset($map['stock']);
        $doMin = $this->update_alerts && isset($map['min']);
        $doQty = $this->update_alerts && isset($map['qty']);
        $line = 1;

        foreach ($data['rows'] as $row) {
            ++$line;
            ++$this->report['rows'];
            $get = function ($f) use ($row, $map) {
                return isset($map[$f], $row[$map[$f]]) ? $row[$map[$f]] : '';
            };
            $ref = self::clean($get('reference'));
            $msg = [];
            $status = 'unchanged';

            $okS = $okM = $okQ = true;
            $stock = $doStock ? self::num($get('stock'), $okS) : null;
            $min = $doMin ? self::num($get('min'), $okM) : null;
            $qty = $doQty ? self::num($get('qty'), $okQ) : null;
            if (!$okS || !$okM || !$okQ || ($min !== null && $min < 0) || ($qty !== null && $qty < 0)) {
                $this->addLine($line, $ref, 'invalid', 'Hay un valor que no es un número (o es negativo en mínimo/cantidad a pedir).');
                continue;
            }
            if ($stock === null && $min === null && $qty === null) {
                $this->addLine($line, $ref, 'unchanged', 'Fila sin valores que actualizar.');
                continue;
            }

            /* --- destino del stock --- */
            $target = null;
            if ($stock !== null) {
                $target = $this->findTarget($useIds ? Tools::strtolower(self::clean($get('type'))) : '', $useIds ? (int) self::clean($get('id')) : 0, $ref, $err);
                if (!$target) {
                    if ($min === null && $qty === null) {
                        $this->addLine($line, $ref, 'not_found', $err);
                        continue;
                    }
                    // Los avisos van por referencia: se guardan aunque no se pueda poner el stock
                    $msg[] = 'Stock no actualizado: ' . $err;
                    ++$this->report['not_found'];
                    $status = 'not_found';
                }
            }
            if ($ref === '' && $target) {
                $ref = $target['reference'];
            }

            /* --- stock --- */
            if ($stock !== null && $target) {
                $key = $target['id_product'] . '-' . $target['id_product_attribute'];
                $old = isset($stockMap[$key]) ? (int) $stockMap[$key] : 0;
                $new = $this->add_stock ? $old + $stock : $stock;
                if ($new !== $old) {
                    if (!$this->dry) {
                        StockAvailable::setQuantity($target['id_product'], $target['id_product_attribute'], $new);
                        $stockMap[$key] = $new;
                    }
                    $msg[] = 'Stock ' . $old . ' → ' . $new;
                    ++$this->report['stock_changed'];
                    $status = 'changed';
                } else {
                    $msg[] = 'Stock sin cambios (' . $old . ')';
                }
            }

            /* --- aviso (por referencia, como antes) --- */
            if ($min !== null || $qty !== null) {
                if ($ref === '') {
                    $this->addLine($line, $ref, 'not_found', 'Sin referencia: el aviso se guarda por referencia.');
                    continue;
                }
                $cur = isset($alerts[$ref]) ? $alerts[$ref] : null;
                $newMin = $min !== null ? $min : ($cur ? $cur['min_stock'] : 0);
                $newQty = $qty !== null ? $qty : ($cur ? $cur['qty'] : 0);
                // Sin aviso previo y con 0/0 no hay nada que guardar (el archivo «stock y avisos» trae 0 en todos)
                if (!$cur && $newMin === 0 && $newQty === 0) {
                    $msg[] = 'Sin aviso';
                } elseif (!$cur || $cur['min_stock'] !== $newMin || $cur['qty'] !== $newQty) {
                    if (!$this->dry) {
                        Db::getInstance()->execute(
                            'INSERT INTO `' . _DB_PREFIX_ . Mystocks::TABLE . '` (`reference`, `min_stock`, `qty`)
                            VALUES (\'' . pSQL($ref) . '\', ' . (int) $newMin . ', ' . (int) $newQty . ')
                            ON DUPLICATE KEY UPDATE `min_stock` = ' . (int) $newMin . ', `qty` = ' . (int) $newQty
                        );
                    }
                    $alerts[$ref] = ['min_stock' => $newMin, 'qty' => $newQty];
                    $msg[] = 'Aviso: mínimo ' . ($cur ? $cur['min_stock'] : '—') . ' → ' . $newMin . ', a pedir ' . ($cur ? $cur['qty'] : '—') . ' → ' . $newQty;
                    ++$this->report['alert_changed'];
                    $status = $status === 'not_found' ? 'partial' : 'changed';
                } else {
                    $msg[] = 'Aviso sin cambios (mínimo ' . $newMin . ', a pedir ' . $newQty . ')';
                }
            }

            if ($status === 'unchanged') {
                ++$this->report['unchanged'];
            }
            if ($status === 'not_found' && $msg) {
                $status = 'partial';
            }
            $this->addLine($line, $ref, $status, implode(' · ', $msg), false);
        }

        return $this->report;
    }

    private function addLine($line, $ref, $status, $text, $count = true)
    {
        if ($count && isset($this->report[$status])) {
            ++$this->report[$status];
        }
        $this->report['lines'][] = ['line' => $line, 'reference' => $ref, 'status' => $status, 'text' => $text];
    }

    /**
     * Busca el producto/combinación al que se aplica el stock.
     *
     * @return array|null [id_product, id_product_attribute, reference]
     */
    private function findTarget($type, $id, $ref, &$err)
    {
        $err = '';
        $db = Db::getInstance();
        if ($id > 0 && ($type === 'combination' || $type === 'combinacion')) {
            $r = $db->getRow('SELECT `id_product`, `reference` FROM `' . _DB_PREFIX_ . 'product_attribute` WHERE `id_product_attribute` = ' . (int) $id);
            if ($r) {
                return ['id_product' => (int) $r['id_product'], 'id_product_attribute' => $id, 'reference' => trim((string) $r['reference'])];
            }
            $err = 'No existe la combinación con ID ' . $id . '.';

            return null;
        }
        if ($id > 0 && ($type === 'product' || $type === 'producto')) {
            $r = $db->getRow('SELECT `reference`, (SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product_attribute` pa WHERE pa.`id_product` = p.`id_product`) AS combis FROM `' . _DB_PREFIX_ . 'product` p WHERE p.`id_product` = ' . (int) $id);
            if (!$r) {
                $err = 'No existe el producto con ID ' . $id . '.';

                return null;
            }
            if ((int) $r['combis'] > 0) {
                $err = 'El producto ' . $id . ' tiene combinaciones: el stock se pone en cada combinación.';

                return null;
            }

            return ['id_product' => $id, 'id_product_attribute' => 0, 'reference' => trim((string) $r['reference'])];
        }
        if ($ref === '') {
            $err = 'Fila sin referencia.';

            return null;
        }
        $combis = Mystocks::combinationsByReference($ref);
        if (count($combis) === 1) {
            return ['id_product' => (int) $combis[0]['id_product'], 'id_product_attribute' => (int) $combis[0]['id_product_attribute'], 'reference' => $ref];
        }
        if (count($combis) > 1) {
            $err = 'La referencia está repetida en ' . count($combis) . ' combinaciones: no se sabe cuál actualizar. Usa el archivo «Stock y avisos de todos los productos», que lleva el ID.';

            return null;
        }
        $prods = Mystocks::productsByReference($ref);
        if (count($prods) === 1) {
            if ((int) $prods[0]['combis'] > 0) {
                $err = 'Es la referencia de un producto con combinaciones: el stock se pone en cada combinación (con su propia referencia).';

                return null;
            }

            return ['id_product' => (int) $prods[0]['id_product'], 'id_product_attribute' => 0, 'reference' => $ref];
        }
        if (count($prods) > 1) {
            $err = 'La referencia está repetida en ' . count($prods) . ' productos.';

            return null;
        }
        $err = 'No hay ningún producto ni combinación con esta referencia.';

        return null;
    }
}
