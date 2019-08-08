<?php


include "ContentsDatabase.php";

$rootContentPath = 'Contents/Root';

Content::CreateGlobalTagMap($rootContentPath);

var_dump(Content::GlobalTagMap());

Content::SaveGlobalTagMapMetaFile();

Content::LoadGlobalTagMapMetaFile();
var_dump(Content::GlobalTagMap());




?>