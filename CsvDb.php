<?php
/**
 * CsvDb.php
 * 最終完整版
 *
 * 功能與特性：
 * 1. 標準化回應
 *    - 所有操作統一回傳格式：
 *      [
 *          'success' => bool,      // 操作是否成功
 *          'message' => string,    // 操作訊息，可直接顯示
 *          'id'      => mixed,     // 操作對象的主鍵 ID (如新增、更新、刪除)
 *          'data'    => array      // 相關資料，查詢、搜尋或新增資料等
 *      ]
 *
 * 2. 自動 Trim
 *    - 讀取與寫入資料時，自動清除欄位前後的空白與隱形換行符號。
 *
 * 3. 自動 Sanitize
 *    - 防止 Excel 注入公式（開頭為 =、+、-、@ 的值會自動加上單引號）
 *    - 移除 Null Byte 避免字串截斷或安全漏洞
 *
 * 4. 原子寫入
 *    - 寫入 CSV 時使用暫存檔 + rename 確保檔案不會半途中斷或損毀
 *
 * 5. 檔案鎖
 *    - 讀取時使用共享鎖 (LOCK_SH)
 *    - 寫入或修改時使用獨佔鎖 (LOCK_EX)
 *    - 避免多個程序同時寫入導致資料錯亂
 *
 * 使用說明：
 *
 * 1. 初始化：
 *    $db = new CsvDb('data.csv', ['name','age','email']);
 *
 * 2. 查詢資料：
 *    - 全部資料：
 *      $result = $db->select();
 *    - 篩選資料：
 *      $result = $db->select(['name' => 'Alice']);
 *
 * 3. 搜尋資料：
 *    $result = $db->search('keyword');
 *    - 會在所有欄位進行關鍵字比對
 *
 * 4. 新增資料：
 *    $result = $db->insert([
 *        'name'  => 'Alice',
 *        'age'   => 25,
 *        'email' => 'alice@example.com'
 *    ]);
 *    - 回傳包含新增資料與自動生成的 system_id
 *
 * 5. 更新資料：
 *    $result = $db->update($id, ['age' => 26]);
 *    - 如果找到指定 ID，會更新欄位
 *
 * 6. 刪除資料：
 *    $result = $db->delete($id);
 *    - 刪除指定 ID 的資料
 *
 * 注意事項：
 * - CSV 檔案第一行為欄位名稱，建議與 $columns 對應
 * - 建議所有欄位名稱不要使用空格或特殊符號
 * - 所有方法皆返回標準化陣列，可直接使用 $result['success'], $result['data'] 等
 */


class CsvDb {
    private $csvFile;
    private $lockFile;
    private $headers;
    private $primaryKey = 'system_id';

    public function __construct($filename, $columns = []) {
        $this->csvFile = $filename;
        $this->lockFile = $filename . '.lock';
        if (!in_array($this->primaryKey, $columns)) {
            array_unshift($columns, $this->primaryKey);
        }
        $this->headers = $columns;
        $this->init();
    }

    private function init() {
        if (!file_exists($this->lockFile)) {
            touch($this->lockFile);
            chmod($this->lockFile, 0666);
        }
        if (!file_exists($this->csvFile)) {
            $f = fopen($this->csvFile, 'w');
            fwrite($f, "\xEF\xBB\xBF");
            fputcsv($f, $this->headers);
            fclose($f);
            chmod($this->csvFile, 0666);
        } else {
            $f = fopen($this->csvFile, 'r');
            $bom = fread($f, 3);
            if ($bom !== "\xEF\xBB\xBF") rewind($f);
            $existingHeaders = fgetcsv($f);
            fclose($f);
            
            // 優化：讀取現有 header 時也做 trim，確保欄位名稱乾淨
            if ($existingHeaders) {
                $this->headers = array_map('trim', $existingHeaders);
            }
        }
    }

    /**
     * 輔助函式：產生標準化回應格式
     */
    private function response($success, $message, $id = null, $data = null) {
        return [
            'success' => $success,
            'message' => $message,
            'id'      => $id,
            'data'    => $data
        ];
    }

    /**
     * 資料清洗 (防範 NULL Byte 與 Excel 注入)
     */
    private function sanitize($data) {
        $cleanData = [];
        foreach ($data as $key => $value) {
            $val = (string)$value;
            $val = str_replace("\0", "", $val); // 移除 NULL Byte
            
            // 防範 Excel 公式注入
            if (preg_match('/^[=\+\-@]/', $val)) {
                $val = "'" . $val;
            }
            $cleanData[$key] = $val;
        }
        return $cleanData;
    }

    private function process($callback) {
        $fp = fopen($this->lockFile, 'r+');
        if (flock($fp, LOCK_EX)) {
            try {
                $rows = $this->readRaw();
                
                // callback 回傳結構: ['save' => bool, 'data' => $rows, 'result' => 標準化回應]
                $result = $callback($rows);

                if (isset($result['save']) && $result['save'] === true) {
                    $this->writeRaw($result['data']);
                }

                flock($fp, LOCK_UN);
                fclose($fp);
                
                return isset($result['result']) ? $result['result'] : $this->response(true, 'Operation completed');

            } catch (Exception $e) {
                flock($fp, LOCK_UN);
                fclose($fp);
                return $this->response(false, 'Error: ' . $e->getMessage());
            }
        } else {
            fclose($fp);
            return $this->response(false, 'System busy: Cannot lock database');
        }
    }

    /**
     * 讀取原始資料 (內部用) - 已修正換行符號問題
     */
    private function readRaw() {
        $rows = [];
        if (($f = fopen($this->csvFile, 'r')) !== false) {
            $bom = fread($f, 3);
            if ($bom !== "\xEF\xBB\xBF") rewind($f);
            
            $headers = fgetcsv($f);
            
            if ($headers) {
                $headers = array_map('trim', $headers); // 清除 header 空白

                while (($data = fgetcsv($f)) !== false) {
                    if (count($data) == count($headers)) {
                        // ★ 關鍵：清除所有資料的前後空白/換行
                        $data = array_map('trim', $data);
                        $rows[] = array_combine($headers, $data);
                    }
                }
            }
            fclose($f);
        }
        return $rows;
    }

    private function writeRaw($rows) {
        $tempFile = tempnam(dirname($this->csvFile), 'csv_tmp_');
        $f = fopen($tempFile, 'w');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, $this->headers);
        foreach ($rows as $row) {
            $line = [];
            foreach ($this->headers as $h) {
                $line[] = isset($row[$h]) ? $row[$h] : '';
            }
            fputcsv($f, $line);
        }
        fclose($f);
        if (!rename($tempFile, $this->csvFile)) {
            copy($tempFile, $this->csvFile);
            unlink($tempFile);
        }
        chmod($this->csvFile, 0666);
    }

    private function nextId($rows) {
        $ids = array_column($rows, $this->primaryKey);
        $ids = array_filter($ids, 'is_numeric');
        return $ids ? max($ids) + 1 : 1;
    }

    // ================= 公開方法 (標準化回應版) =================

    public function select($filters = []) {
        $fp = fopen($this->lockFile, 'r');
        
        // 使用共享鎖 (LOCK_SH)，允許其他人同時讀取
        if (flock($fp, LOCK_SH)) {
            try {
                $rows = $this->readRaw();
                
                flock($fp, LOCK_UN);
                fclose($fp);

                if (!empty($filters)) {
                    $rows = array_filter($rows, function($row) use ($filters) {
                        foreach ($filters as $k => $v) {
                            if (!isset($row[$k]) || $row[$k] != $v) return false;
                        }
                        return true;
                    });
                    $rows = array_values($rows); // 重建索引
                }

                return $this->response(true, '查詢成功', null, $rows);

            } catch (Exception $e) {
                flock($fp, LOCK_UN);
                fclose($fp);
                return $this->response(false, '讀取錯誤: ' . $e->getMessage(), null, []);
            }
        } else {
            fclose($fp);
            return $this->response(false, '系統忙碌中 (無法讀取)', null, []);
        }
    }

    public function search($keyword) {
        $fp = fopen($this->lockFile, 'r');
        
        if (flock($fp, LOCK_SH)) {
            try {
                $rows = $this->readRaw();
                flock($fp, LOCK_UN);
                fclose($fp);

                if (empty($keyword)) {
                    return $this->response(true, '關鍵字為空，回傳全部', null, $rows);
                }

                $keyword = strtolower($keyword);
                $rows = array_filter($rows, function($row) use ($keyword) {
                    foreach ($row as $val) {
                        if (strpos(strtolower($val), $keyword) !== false) return true;
                    }
                    return false;
                });
                $rows = array_values($rows);

                return $this->response(true, "搜尋成功，找到 " . count($rows) . " 筆", null, $rows);

            } catch (Exception $e) {
                flock($fp, LOCK_UN);
                fclose($fp);
                return $this->response(false, '搜尋錯誤: ' . $e->getMessage(), null, []);
            }
        } else {
            fclose($fp);
            return $this->response(false, '系統忙碌中', null, []);
        }
    }

    public function insert($data) {
        $data = $this->sanitize($data); 
        
        return $this->process(function($rows) use ($data) {
            $newId = $this->nextId($rows);
            $newRow = [$this->primaryKey => (string)$newId];
            
            foreach ($this->headers as $h) {
                if ($h !== $this->primaryKey) {
                    $newRow[$h] = isset($data[$h]) ? $data[$h] : '';
                }
            }
            
            $rows[] = $newRow;
            
            return [
                'save' => true, 
                'data' => $rows, 
                'result' => $this->response(true, '新增成功', $newId, $newRow)
            ];
        });
    }

    public function update($id, $data) {
        $data = $this->sanitize($data);

        return $this->process(function($rows) use ($id, $data) {
            $found = false;
            foreach ($rows as &$row) {
                if ($row[$this->primaryKey] == $id) {
                    foreach ($data as $k => $v) {
                        if (in_array($k, $this->headers) && $k !== $this->primaryKey) {
                            $row[$k] = $v;
                        }
                    }
                    $found = true;
                    break;
                }
            }
            
            if ($found) {
                return [
                    'save' => true, 
                    'data' => $rows, 
                    'result' => $this->response(true, '更新成功', $id)
                ];
            }
            return [
                'save' => false, 
                'result' => $this->response(false, '找不到該 ID', $id)
            ];
        });
    }

    public function delete($id) {
        return $this->process(function($rows) use ($id) {
            $originalCount = count($rows);
            $rows = array_filter($rows, function($row) use ($id) {
                return $row[$this->primaryKey] != $id;
            });
            
            if (count($rows) < $originalCount) {
                return [
                    'save' => true, 
                    'data' => $rows, 
                    'result' => $this->response(true, '刪除成功', $id)
                ];
            }
            return [
                'save' => false, 
                'result' => $this->response(false, '刪除失敗：找不到該 ID', $id)
            ];
        });
    }
}
?>