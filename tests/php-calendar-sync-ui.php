<?php
require __DIR__.'/fixtures/calendar-sync-view.php';
$checks=0;
function verify($v,$m){global $checks;$checks++;if(!$v)throw new RuntimeException($m);}
foreach(['review','linked','estate','history','settings'] as $tab){
    $_GET=['sync_view'=>$tab];ob_start();MBS_Google_Calendar::render();$html=ob_get_clean();
    verify(strpos($html,'Calendar Sync')!==false,'View renders '.$tab);
    verify(strpos($html,'never edited or deleted')!==false,'Protection visible on '.$tab);
    verify(strpos($html,'<script>alert(1)</script>')===false,'Escaped Google title on '.$tab);
    verify(strpos($html,'name="client_secret"')===false||$tab==='settings','Credentials not mixed into review');
    if($tab==='review'){
        verify(substr_count($html,'<a href="/admin.php?page=mathlin-booking')===30,'25 display groups retain all six reservations in first group');
        verify(strpos($html,'6 Google records with the same visible details')!==false,'Repeated Google records grouped');
        verify(strpos($html,'6 MGF reservations share these candidates')!==false,'Related reservations grouped');
        verify(strpos($html,'29 display groups')!==false,'Group count separate from reservation total');
        verify(substr_count($html,'name="choice[family')===6,'All six independent reference choices retained');
        verify(strpos($html,'<optgroup label=')!==false,'Dropdown options organised into labelled groups');
        verify(strpos($html,'Open in Google')!==false,'Underlying records inspectable');
        verify(strpos($html,'explicitly keep both')===false,'Obsolete duplicate-creation wording removed');
        verify(strpos($html,'24 Oct 2026 – 26 Oct 2026')!==false,'Inclusive all-day date labels');
        verify(strpos($html,'Save reviewed matches — no Google edits')!==false,'Save matches separate from enabling');
        verify(strpos($html,'value="protect:estate1"')!==false&&strpos($html,'adopt:')===false,'Only protected local link choices');
    }
    if($tab==='history')verify(strpos($html,'Protected reference saved')!==false,'History visible');
    if($tab==='estate')verify(strpos($html,'Nothing in this list will be removed')!==false,'Estate entries explicitly protected');
    if($tab==='settings'){
        verify(strpos($html,'Colours for new events')!==false,'New-event colour settings visible');
        verify(strpos($html,'name="colours[estate]"')!==false&&strpos($html,'name="colours[scout]"')!==false,'Separate estate and Scout controls');
        verify(strpos($html,'Existing Google entries keep their current colours')!==false,'No-recolour policy visible');
        verify(strpos($html,'Save new-event colours and pause')!==false,'Colour save explicitly pauses sync');
    }
}
$_GET=['sync_view'=>'review','sync_paged'=>2];ob_start();MBS_Google_Calendar::render();$html=ob_get_clean();verify(substr_count($html,'<a href="/admin.php?page=mathlin-booking')===4,'Second page contains remaining four groups');
echo "Calendar Sync UI: $checks assertions passed.\n";
