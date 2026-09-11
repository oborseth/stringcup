<?php
namespace App\Commands;
use CodeIgniter\CLI\BaseCommand; use CodeIgniter\CLI\CLI;
class DbBackup extends BaseCommand {
    protected $group='Database'; protected $name='db:backup';
    protected $description='Export all application tables to a JSON snapshot before destructive work.';
    public function run(array $p){
        $db=\Config\Database::connect();
        $tables=['identities','api_tokens','messages','idempotency_keys','topics','topic_members','rendezvous','prekey_bundles','prekeys'];
        $out=['taken_at'=>date('c'),'tables'=>[]];
        foreach($tables as $t){
            if(!$db->tableExists($t)) continue;
            $rows=$db->query("SELECT * FROM `$t`")->getResultArray();
            // Binary columns are not JSON-safe; base64 them so the snapshot round-trips.
            foreach($rows as &$r) foreach($r as $k=>&$v)
                if(is_string($v) && !mb_check_encoding($v,'UTF-8')) $v='base64:'.base64_encode($v);
            $out['tables'][$t]=$rows;
            CLI::write(sprintf('  %-20s %d rows', $t, count($rows)));
        }
        $path=WRITEPATH.'backups/snapshot-'.date('Ymd-His').'.json';
        file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
        chmod($path, 0600);
        CLI::write('');
        CLI::write('Snapshot: '.$path.' ('.number_format(filesize($path)).' bytes)', 'green');
    }
}
