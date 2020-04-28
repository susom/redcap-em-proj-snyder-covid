<?php

namespace Stanford\ProjSnyderCovid;

/** @var \Stanford\ProjSnyderCovid\ProjSnyderCovid $module */

use REDCap;

$generatorURL = $module->getUrl('Migrator.php', false, true);
?>
<form enctype="multipart/form-data" action="<?php echo $generatorURL ?>" method="post" name="instrument-migrator"
      id="instrument-migrator">
    <h2>Migrate this</h2>
    <input type="text" name="origin_pid" id="origin_pid "  placeholder="Originating PID">
    <input type="text" name="start_record" id="start_record "  placeholder="Test: Start counter">
    <input type="text" name="last_record" id="last_record "  placeholder="Test: Last counter">
    <input type="submit" id="submit" name="submit" value="Migrate Data">
</form>
