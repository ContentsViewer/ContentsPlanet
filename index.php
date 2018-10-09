<?php

require_once dirname(__FILE__) . "/Module/ContentsDatabaseManager.php";
require_once dirname(__FILE__) . "/Module/OutlineText.php";
require_once dirname(__FILE__) . "/Module/ContentsViewerUtil.php";



OutlineText\Parser::Init();

$rootContentPath = ContentsDatabaseManager::DefalutRootContentPath();

$parentsMaxCount = 3;
$brotherTitleMaxStrWidth = 40;

$plainTextMode = false;

$contentPath =$rootContentPath;
if(isset($_GET['content']))
{
    $contentPath = urldecode($_GET['content']);
}


if(isset($_GET['plainText'])){
    $plainTextMode = true;
}


$currentContent = new Content();
$parents = [];
$children = [];
$leftContent = null;
$rightContent = null;


// コンテンツの取得
$isGetCurrentContent = $currentContent->SetContent($contentPath);

$rootContentPath = ContentsDatabaseManager::GetRelatedRootFile($contentPath);
$metaFileName = ContentsDatabaseManager::GetRelatedMetaFileName($contentPath);

if($isGetCurrentContent && !$plainTextMode)
{
    // コンテンツの設定

    //echo $isOldURL ? "true" : "false";
    
    // CurrentContentのSummaryとBodyをDecode
    $currentContent->SetSummary(OutlineText\Parser::Parse($currentContent->Summary()));
    $currentContent->SetBody(OutlineText\Parser::Parse($currentContent->Body()));


    //ChildContentsの取得
    $childrenPathList = $currentContent->ChildPathList();
    for($i = 0; $i < count($childrenPathList); $i++)
    {
        $child = $currentContent->Child($i);
        if($child !== false){
            $children[] = $child;
        }
    }

    //Parentsの取得
    $parent = $currentContent->Parent();

    for($i = 0; $i < $parentsMaxCount; $i++)
    {
        if($parent === false)
        {
            break;
        }
        $parents[] = $parent;
        $parent = $parent->Parent();
    }

    // echo count($parents);
    //LeftContent, RightContentの取得
    if(isset($parents[0]))
    {
        $parent = $parents[0];
        $brothers = $parent->ChildPathList();
        $myIndex = $currentContent->ChildIndex();

        if($myIndex >= 0)
        {
            if($myIndex > 0)
            {
                $leftContent = $parent->Child($myIndex - 1);
            }
            if($myIndex <  count($brothers) - 1)
            {
                $rightContent = $parent->Child($myIndex + 1);
            }
        }
    }

}

if(!$isGetCurrentContent){
    
    header("HTTP/1.1 404 Not Found");
}


if($plainTextMode && $isGetCurrentContent){
    echo "<!DOCTYPE html><html lang='ja'><head></head><body>";
    echo "<pre style='word-wrap: break-word; white-space: pre-wrap'>";
    echo htmlspecialchars(file_get_contents(Content::RealPath($contentPath)));
    echo "</pre>";
    echo "</body></html>";
    exit();
}


?>




<!DOCTYPE html>
<html lang="ja">

<head>
    <meta charset="UTF-8" />
    <link rel="shortcut icon" href="Client/Common/favicon.ico" type="image/vnd.microsoft.icon" />

    

    <!-- Code表記 -->
    <script type="text/javascript" src="Client/syntaxhighlighter/scripts/shCore.js"></script>
    <script type="text/javascript" src="Client/syntaxhighlighter/scripts/shBrushCpp.js"></script>
    <script type="text/javascript" src="Client/syntaxhighlighter/scripts/shBrushCSharp.js"></script>
    <script type="text/javascript" src="Client/syntaxhighlighter/scripts/shBrushXml.js"></script>
    <script type="text/javascript" src="Client/syntaxhighlighter/scripts/shBrushPhp.js"></script>
    <script type="text/javascript" src="Client/syntaxhighlighter/scripts/shBrushPython.js"></script>
    <script type="text/javascript" src="Client/syntaxhighlighter/scripts/shBrushJava.js"></script>
    <link type="text/css" rel="stylesheet" href="Client/syntaxhighlighter/styles/shCoreDefault.css" />
    <script type="text/javascript">SyntaxHighlighter.all();</script>



    <link rel="stylesheet" href="Client/OutlineText/OutlineTextStandardStyle.css" />
    <link rel="stylesheet" href="Client/ContentsViewer/ContentsViewerStandard.css" />
    <script type="text/javascript" src="Client/ContentsViewer/ContentsViewerStandard.js"></script>




    <?php

    if($isGetCurrentContent){
    
        //title作成
        $title = "";
        $title .= $currentContent->Title();
        if(isset($parents[0]))
        {
            $title .=" | " . $parents[0]->Title();
        }

        echo "<title>".$title."</title>";
    }
    else{
        
        echo "<title>NotExist</title>";
    }


    readfile("Client/Common/CommonHead.html");

    ?>

</head>

<body>

    <div id="HeaderArea">
        <a href="<?=CreateContentHREF($rootContentPath)?>">ContentsViewer</a>
    </div>

    

    <?php


    //CurrentContentを取得したかどうか
    if(!$isGetCurrentContent)
    {
        $isFatalError = true;
        echo '<div id="ErrorMessageBox">';
        echo  '<h1>Error!</h1> <br/>存在しないContentにアクセスした可能性があります.';
        
        echo '</div>';

        exit;
    }



    //---Navigator作成---------------------------------------------------------------------------------------------------
    $navigator = "";
    $navigator.= "<div class='Navi'>";
    $navigator .= "<ul>";
    CreateNavHelper($parents, count($parents)- 1, $currentContent, $children, $navigator);
    $navigator .= "</ul>";
    $navigator.= "</div>";


    //---LeftSideArea----------------------------------------------------------------------------------------------------

    echo "<div id ='LeftSideArea'>";
    echo $navigator;
    echo "</div>";


    // --- RightSideArea ----------------------------------------------------------------------------
    echo "<div id = 'RightSideArea'>";
    echo "Index";
    echo "<div class='Navi'></div>";
    echo "<a href='" . CreateHREFForPlainTextMode() . "'>このページのソースコードを表示</a>";
    echo "</div>";


    ////---MainArea--------------------------------------------------------------------------------------------------------
    echo '<div id="MainArea">';

    
    //最終更新欄
    echo '<div class="FileDateField">';
    echo "<img src='Client/Common/CreatedAtStampA.png' alt='公開日'>: ". $currentContent->CreatedAt()
    . " <img src='Client/Common/UpdatedAtStampA.png' alt='更新日'>: " . $currentContent->UpdatedAt();
    echo '</div>';


    echo "<ul class='tag-links'>";
    //echo $currentContent->Tags()[0];
    foreach($currentContent->Tags() as $name){
        echo "<li><a href='" . CreateTagDetailHREF($name, $metaFileName) . "'>" . $name . "</a></li>";
    }
    echo "</ul>";


    // 概要欄
    echo '<div id="SummaryField" class="Summary">';
    echo $currentContent->Summary();

    if($currentContent->IsRoot()){
        ContentsDatabaseManager::LoadRelatedTagMap($contentPath);
        $tagMap = Content::GlobalTagMap();
        echo CreateNewBox($tagMap);

        
        echo "<h2>タグ一覧</h2>";
        echo CreateTagListElement($tagMap, $metaFileName);
    }
    echo '</div>';
    
    // 目次欄(小画面で表示される)
    echo "<div id = 'IndexAreaOnSmallScreen'>";
    echo "Index";
    echo "</div>";

    //本編
    echo '<div id="MainContentField" class="MainContent">';
    echo $currentContent->Body();
    echo '</div>';

    //子コンテンツ
    echo '<div id="ChildrenField">';
    for($i = 0; $i < count($children); $i++)
    {
        echo "<div style='width:100%; display: table'>";

        //A-----
        echo "<div style='display: table-cell'>";

        echo '<a class="LinkButtonBlock" href ="'.CreateContentHREF($children[$i]->Path()).'">';
        echo $children[$i]->Title();
        echo '</a>';

        echo "</div>";
        //---

        ////B-----
        //echo "<div class='ChildDetailButton' style='display:table-cell;  width:10%;' "
        //.'onmouseover="QuickLookMouse('
        //."'ChildContent"
        //.$i
        //."')"
        //.'" '
        //.'ontouchstart="QuickLookTouch('
        //."'ChildContent"
        //.$i
        //."')"
        //.'" '
        //.'onmouseout="ExitQuickLookMouse()" '
        //.'ontouchend="ExitQuickLookTouch()"'
        //."></div>";
        ////---

        ////C-----
        //echo "<div class='ContentContainer' "
        //."id='ChildContent".$i."Container"."'"
        //.">";
        //echo "<h1>" . $children[$i]->GetTitle(). "</h1>";
        //echo "<div>" . $children[$i]->GetAbstract(). "</div>";
        //echo "<div>" . $children[$i]->GetRootContent(). "</div>";
        //echo "</div>";
        ////---

        echo "</div>";

    }
    echo '</div>';

    

    //---MainPageBottomAppearingOnSmallScreen----------------------------------
    echo "<div id='MainPageBottomAppearingOnSmallScreen'>";
    echo "<a href='" . CreateHREFForPlainTextMode() . "'>このページのソースコードを表示</a>";

    echo $navigator;

    echo "</div>";

    echo '</div>';
    // end main area ---------


    ?>

    <div id='edit-link-area'>
    <a href='./login.php'>Manage</a>    <a href='./content-editor.php?content=<?=$currentContent->Path()?>'>Edit</a>
    </div>

    <?php

    //---TopArea--------------------------------------------------------------------------------------------------------
    echo '<div id="TopArea">';

    //親コンテンツ
    echo '<div id="ParentField" class="ParentField">';
    //echo var_dump($parents);
    for($i = 0; $i < count($parents); $i++)
    {
        $index = count($parents) - $i - 1;
        if($parents[$index]===false)
        {
            echo '<p class="LinkButtonBlock">Error; 存在しないコンテンツです</p>';
        }
        else
        {
            echo '<a  href ="'.CreateContentHREF($parents[$index]->Path()).'">';
            echo $parents[$index]->Title();
            echo '</a>';
        }
        echo ' &gt; ';
    }
    echo '</div>';

    //タイトル欄
    echo '<div id="TitleField" class="Title">';
    echo $currentContent->Title();
    echo '</div>';

    echo' </div>';

    
    //---BottomRightArea----------------------------------------------------------------------------------------------
    //echo $myIndex;
    if(!is_null($rightContent))
    {

        if($rightContent !== false)
        {
            echo '<a id="BottomRightArea"  href ="'.CreateContentHREF($rightContent->Path()).'">';
            echo  mb_strimwidth($rightContent->Title(), 0, $brotherTitleMaxStrWidth, "...", "UTF-8") . " &gt;";
            echo '</a>';
            //echo "<div id = 'RightContentContainer' class='ContentContainer'>";
            //echo "<h1>" . $rightContent->GetTitle(). "</h1>";
            //echo "<div>" . $rightContent->GetAbstract(). "</div>";
            //echo "<div>" . $rightContent->GetRootContent(). "</div>";
            //echo "</div>";
        }
    }

    //---BottomLeftArea------------------------------------------------------------------------------------------------
    if(!is_null($leftContent))
    {

        if($leftContent !== false)
        {
            echo '<a id="BottomLeftArea" href ="'.CreateContentHREF($leftContent->Path()).'">';
            echo  "&lt; ". mb_strimwidth($leftContent->Title(), 0, $brotherTitleMaxStrWidth, "...", "UTF-8");
            echo '</a>';
            //echo "<div id = 'LeftContentContainer' class='ContentContainer'>";
            //echo "<h1>" . $leftContent->GetTitle(). "</h1>";
            //echo "<div>" . $leftContent->GetAbstract(). "</div>";
            //echo "<div>" . $leftContent->GetRootContent(). "</div>";
            //echo "</div>";
        }
    }


    function CreateHREFForPlainTextMode(){
        $query = $_SERVER["QUERY_STRING"] . "&plainText";

        return "?" . $query;
    }

    function CreateNavHelper(&$parents, $parentsIndex, &$currentContent, &$children,  &$navigator){
        if($parentsIndex < 0){

            $navigator.=  "<li>";
            $navigator.=  "<a class = 'Selected' href='" . CreateContentHREF($currentContent->Path()) . "'>" . $currentContent->Title() . "</a>";
            $navigator.=  "</li>";

            $navigator.="<ul>";
            foreach($children as $c){

                $navigator.=  "<li>";
                $navigator.=  "<a href='" . CreateContentHREF($c->Path()) . "'>" . $c->Title() . "</a>";
                $navigator.=  "</li>";
            }
            $navigator.="</ul>";

            return;
        }

        $childrenCount = $parents[$parentsIndex]->ChildCount();

        $navigator.=  "<li>";
        $navigator.=  "<a class = 'Selected' href='" . CreateContentHREF($parents[$parentsIndex]->Path()) . "'>" . $parents[$parentsIndex]->Title() . "</a>";
        $navigator.=  "</li>";

        $navigator.=  "<ul>";
        if($parentsIndex == 0){
            $currentContentIndex = $currentContent->ChildIndex();
            for($i = 0; $i < $childrenCount; $i++){

                $child = $parents[$parentsIndex]->Child($i);
                if($child === false){
                    continue;
                }

                if($i == $currentContentIndex){
                    $navigator.=  "<li>";
                    $navigator.=  "<a class = 'Selected' href='" . CreateContentHREF($child->Path()) . "'>" . $child->Title() . "</a>";
                    $navigator.=  "</li>";

                    $navigator.="<ul>";
                    foreach($children as $c){
                        $navigator.=  "<li>";
                        $navigator.=  "<a href='" . CreateContentHREF($c->Path()) . "'>" . $c->Title() . "</a>";
                        $navigator.=  "</li>";
                    }
                    $navigator.="</ul>";
                }
                else{
                    $navigator.=  "<li>";
                    $navigator.=  "<a href='" . CreateContentHREF($child->Path()) . "'>" . $child->Title() . "</a>";
                    $navigator.=  "</li>";
                }
            }
        }
        else{
            $nextParentIndex = $parents[$parentsIndex - 1]->ChildIndex();
            for($i = 0; $i < $childrenCount; $i++){

                if($i == $nextParentIndex){
                    CreateNavHelper($parents, $parentsIndex-1, $currentContent, $children, $navigator);
                }
                else{
                    $child = $parents[$parentsIndex]->Child($i);
                    if($child === false){
                        continue;
                    }
                    $navigator.=  "<li>";
                    $navigator.=  "<a href='" . CreateContentHREF($child->Path()) . "'>" . $child->Title() . "</a>";
                    $navigator.=  "</li>";
                }
            }
        }
        $navigator.=  "</ul>";
        return;
    }
    ?>
</body>
</html>
