<?php

/**
 * 参照する変数
 *  $vars['directoryPath'] = '/Master/Contents/Directory'
 */
require_once(MODULE_DIR . '/Stopwatch.php');
require_once(MODULE_DIR . '/ContentsViewerUtils.php');
require_once(MODULE_DIR . '/ContentDatabaseControls.php');
require_once(MODULE_DIR . '/ContentDatabaseContext.php');
require_once(MODULE_DIR . '/PathUtils.php');
require_once(MODULE_DIR . '/Authenticator.php');

use ContentsViewerUtils as CVUtils;
use ContentDatabaseControls as DBControls;
use ContentDatabaseContext as DBContext;

// FIXME: UNIFY the representation of paths in the whole application!!!!
//  Relative or Absolute
//  if Relative, paths start with '.'?

// relative path representation (normalized)
//  ex) Master/Contents/Test
//      x)  ./Master/Contents/Test
$directoryPath = PathUtils\canonicalize($vars['directoryPath']);

$contentsFolder = PathUtils\canonicalize($vars['contentsFolder']);

Authenticator::GetUserInfo($vars['owner'], 'enableRemoteEdit',  $enableRemoteEdit);


$editMode = isset($_GET['cmd']) && ($_GET['cmd'] === 'edit');

if ($editMode) {
    Authenticator::RequireLoginedSession($_SERVER["REQUEST_URI"]);

    $username = Authenticator::GetLoginedUsername();
    if (!Authenticator::IsFileOwner($directoryPath, $username)) {
        // ファイル所有者が違うため再ログインを要求
        require(FRONTEND_DIR . '/403.php');
        exit();
    }

    Authenticator::GetUserInfo($username, 'remoteURL',  $remoteURL);

    if ($enableRemoteEdit) {
        // NOTE: $directoryPath should start with $contentsFolder.
        //  It is assured by the above code `Authenticator::IsFileOwner()`.

        // ex)
        //  $directoryPath : 'Master/Contents/Test'
        //  $contentsFolder: 'Master/Contents'
        //  $targetPath    : '/Test'
        //
        //  $directoryPath : 'Master/Contents'
        //  $contentsFolder: 'Master/Contents'
        //  $targetPath    : ''
        //
        // NOTE: Why does target path sometimes start with a slash and sometimes not?
        //  Because it depends on the situation whether a slash is needed or not.
        //
        //  Consider the case where target path points to the root folder.
        //  $remoteURL should be 'https://git/tree/master' not 'https://git/tree/master/'.
        //  So in this case, $targetPath should be empty.
        //
        //  However, when target path points to the specific folder or file ,such as 'Master/Contents/Test',
        //  $remoteURL should be 'https://git/tree/master/Test' and then $targetPath should be '/Test'.
        //
        $targetPath = substr($directoryPath, strlen($contentsFolder));

        $remoteURL = str_replace('{TARGET_PATH}', $targetPath, $remoteURL);

        header("location: $remoteURL");
        exit();
    }
}


$vars['warningMessages'] = [];
$vars['pageBuildReport']['times'] = [];
$vars['pageBuildReport']['updates'] = [];

// --- Start measuring build time ---
$swBuild = new Stopwatch();
$swBuild->Start();

$vars['rootContentPath'] = $vars['contentsFolder'] . '/' . ROOT_FILE_NAME . DBControls\GetLayerSuffix($vars['layerName']);

$dbContext = new DBContext($vars['rootContentPath']);
$dbContext->LoadMetadata();

// relative path (normalized)
// FIXME:  UNIFY the representation of paths in the whole application!!!!
$rootDirectory = PathUtils\canonicalize(GetTopDirectory($vars['rootContentPath']));

$parents = [];
$iter = $directoryPath;
for ($i = 0; $i < 3; $i++) {
    $iter = dirname($iter);
    if ($rootDirectory === $iter) {
        break;
    }
    $parents[] = $iter;
}


$currentDirname = basename($directoryPath);
$vars['pageTitle'] = Localization\Localize('directory', 'Directory') . ': ' . $currentDirname;
$vars['pageHeading']['title'] = '<span style="word-wrap: break-word;">' . $vars['pageTitle'] . '</span>';
if (!empty($parents)) {
    $vars['pageTitle'] .= ' | ' . basename($parents[0]);
}

$result = GetFilesAndSubDirs($directoryPath);
$subDirs = $result['subDirs'];
$files = [];
$contents = [];
foreach ($result['files'] as $file) {
    if (pathinfo($file, PATHINFO_EXTENSION) == 'content') {
        $contents[] = $file;
    } else {
        $files[] = $file;
    }
}

$vars['rootChildContents'] = $dbContext->GetRootChildContens();

$vars['pageHeading']['parents'] = [];
foreach ($parents as $parent) {
    $vars['pageHeading']['parents'][] = [
        'title' => basename($parent), 'path' => CVUtils\CreateDirectoryHREF("/{$parent}", $vars['language'])
    ];
}

$vars['navigator'] = CreateNavi($parents, $directoryPath, $subDirs, $vars['language']);
$vars['childList'] = [];

$head = '';
if ($editMode) {
    $head .= '<link href="' . CLIENT_URI . '/DirectoryViewer/edit.css" rel="stylesheet">';
}
$vars['additionalHeadScript'] = $head;

$vars['contentSummary'] = '';

if (!empty($subDirs) > 0 || !empty($contents) > 0 || !empty($files) > 0) {
} else {
    $vars['contentSummary'] = '<p>' .
        Localization\Localize(
            'directory-viewer.directoryIsEmpty',
            'This "<span style="word-wrap: break-word;">Direcotry: {0}</span>" is empty.',
            $directoryPath
        ) .
        '</p>';
}

$vars['rightPageTabs'] = [
    [
        'selected' => $editMode,
        'innerHTML' => '<a href="?cmd=edit"'
            . ($enableRemoteEdit ? ' target="_blank"' : ''). '>' 
            . Localization\Localize('edit', 'Edit') . '</a>'
    ],
    [
        'selected' => !$editMode,
        'innerHTML' => '<a href="' . CVUtils\CreateDirectoryHREF("/{$directoryPath}", $vars['language'])
            . '">' . Localization\Localize('view', 'View') . '</a>'
    ],
];


$body = '';

if ($editMode) {
    $body .= "
<div id='dropField' class='drop-field'>Drop files here to upload</div>

<div style='margin-top: 0.5em;' class='button-group'>
    <button id='newDirectoryButton'>New Directory</button>
    <button id='newFileButton'>New File</button>
</div>
    ";
}

if (!empty($subDirs) || $editMode) {
    $body .= '<h3>' . Localization\Localize('subdirectories', 'Subdirectories') . '</h3>';
    $body .= '<div id="directoryList" class="directory-container">';
    foreach ($subDirs as $subDir) {
        $item = '<a class="directory" href="' . CVUtils\CreateDirectoryHREF("/${subDir}", $vars['language']) . '">';
        $item .= '<div class="icon folder-icon"></div>';
        $item .= '<div class="name">' . basename($subDir) . '</div>';
        $item .= '</a>';

        if ($editMode) {
            $body .= "<div data-path='${subDir}' data-file-type='directory'>${item}</div>";
        } else {
            $body .= $item;
        }
    }
    $body .= '</div>';
}

if (!empty($contents) || $editMode) {
    $body .= '<h3>' . Localization\Localize('contents', 'Contents') . '</h3>';
    $body .= '<div id="contentList" class="card-wrapper">';

    foreach ($contents as $contentPath) {
        $content = $dbContext->database->get(\PathUtils\replaceExtension($contentPath, ''));
        if ($content) {
            $href = CVUtils\CreateContentHREF($content->path);
            $title = NotBlankText([$content->title, basename($content->path)]);
            $text = CVUtils\GetDecodedText($content);
            $footer = basename($content->realPath);

            $item = CVUtils\CreateContentCard($title, $text['summary'], $href, $footer);

            if ($editMode) {
                $path =  PathUtils\canonicalize($content->path) . Content::EXTENSION;
                $body .= "<div data-path='${path}' data-file-type='content' data-url='${href}'>${item}</div>";
            } else {
                $body .= $item;
            }
        }
    }
    $body .= '</div>';
}

if (!empty($files) || $editMode) {
    $body .= '<h3>' . Localization\Localize('files', 'Files') . '</h3>';
    $body .= '<div id="fileList" class="file-container">';
    foreach ($files as $file) {
        $item = '<a class="file" href="' . CVUtils\CreateFileHREF("/${file}") . '">';
        $item .= '<div class="thumbnail">';

        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'bmp'])) {
            $item .= '<img src="' . CVUtils\CreateFileHREF("/${file}") . '" loading="lazy">';
        }

        $item .= '</div>';
        $item .= '<div class="file-title"><div class="icon file-icon"></div>';
        $item .= '<div class="name">' . basename($file) . '</div></div>';
        $item .= '</a>';

        if ($editMode) {
            $body .= "<div data-path='${file}' data-file-type='file'>${item}</div>";
        } else {
            $body .= $item;
        }
    }
    $body .= '</div>';
}

if ($editMode) {
    $body .= "
<div id='controlPanel' class='control-panel'>
    <div>Selected: <span id='selectedField'></span></div>
    <div class='button-group' style='margin-top: 0.5em;'>
        <button id='editButton' style='width: 75px'>Edit</button>
        <button id='moveButton' style='width: 75px'>Move</button>
        <button id='deleteButton' style='width: 75px'>Delete</button>
    </div>
</div>
<div id='loadingBox'>
    <span id='remaining'></span>
    <div class='spinner'>
        <div class='cube1'></div>
        <div class='cube2'></div>
    </div>
</div>
";

    $body .= '<script src="' . CLIENT_URI . '/DirectoryViewer/edit.js"></script>';

    $rootURI = ROOT_URI;
    $serviceURL = SERVICE_URI . '/file-management-service.php';
    $body .= "
<script>
    const token = document.getElementsByName('token').item(0).content
    const client = FileManagerClient('{$serviceURL}', token)
    const directoryPath = '${directoryPath}'

    const controlPanel = document.getElementById('controlPanel')

    const remainingController = (() => {
        const remaining = document.getElementById('remaining')
        const loadingBox = document.getElementById('loadingBox')

        let timeout = null
    
        let remainingCount = 0
    
        function incrementRemaining() {
            if (timeout) {
                clearTimeout(timeout)
                timeout = null
            }
            ++remainingCount
            remaining.textContent = remainingCount
            if (remainingCount > 0) {
                loadingBox.setAttribute('visible', '')
            }
        }
    
        function decrementRemaining() {
            --remainingCount
            remaining.textContent = remainingCount
            if (remainingCount <= 0) {
                timeout = setTimeout(() => {
                    loadingBox.removeAttribute('visible')
                }, 1000)
            }
        }

        return {
            incrementRemaining: incrementRemaining,
            decrementRemaining: decrementRemaining
        }
    })()

    let elements, element, i
    let selected = null

    const directoryList = element = document.getElementById('directoryList')
    elements = directoryList.children
    for (i = 0; i < elements.length; ++i) {
        setupFileElement(elements[i])
    }

    const contentList = document.getElementById('contentList')
    elements = contentList.children
    for (i = 0; i < elements.length; ++i) {
        setupFileElement(elements[i])
    }
    
    const fileList = document.getElementById('fileList')
    elements = fileList.children
    for (i = 0; i < elements.length; ++i) {
        setupFileElement(elements[i])
    }
    

    const dropField = document.getElementById('dropField')
    dropField.addEventListener('dragover', function(e) {
        e.preventDefault()
    })
    dropField.addEventListener('drop', function(e) {
        e.preventDefault()
        const files = e.dataTransfer.files
        for (let i = 0; i < files.length; ++i) {
            remainingController.incrementRemaining()
            client.uploadFile(directoryPath, files[i])
            .then(response => {
                const {filePath} = response
                appendFileItem(filePath)
            })
            .catch(error => {
                console.error(error)
            })
            .then(() => {
                remainingController.decrementRemaining()
            })
        }
    })

    const newDirectoryButton = document.getElementById('newDirectoryButton')
    newDirectoryButton.addEventListener('click', function(e) {
        const path = window.prompt('New Directory Path', `\${directoryPath}/new-directory`)
        if (!path) return
        
        remainingController.incrementRemaining()
        client.createDirectory(path)
        .then(response => {
            const {directoryPath} = response
            appendDirectoryItem(directoryPath)
        })
        .catch(error => {
            window.alert(`Create Directory Failed.\nPath:\${path}\n\${error}`)
        })
        .then(() => {
            remainingController.decrementRemaining()
        })
    })

    const newFileButton = document.getElementById('newFileButton')
    newFileButton.addEventListener('click', function(e) {
        const path = window.prompt('New File Path', `\${directoryPath}/new.content`)
        if (!path) return
        
        remainingController.incrementRemaining()
        client.createFile(path)
        .then(response => {
            const {filePath} = response
            appendFileItem(filePath)
        })
        .catch(error => {
            window.alert(`Create File Failed.\nPath:\${path}\n\${error}`)
        })
        .then(() => {
            remainingController.decrementRemaining()
        })
    })

    function select(element) {
        controlPanel.setAttribute('visible', '')

        if (selected) selected.classList.remove('selected')

        element.classList.add('selected')

        const { path, fileType } = element.dataset

        const selectedField = document.getElementById('selectedField')

        selectedField.innerText = path
        editButton.disabled = (fileType !== 'content')

        selected = element
    }

    function deselect() {
        controlPanel.removeAttribute('visible', '')
        if (selected) selected.classList.remove('selected')

        selected = null
    }

    function setupFileElement(element) {
        element.addEventListener('click', function(e) {
            select(this)
            e.preventDefault()
        })
    }

    const editButton = document.getElementById('editButton')
    editButton.addEventListener('click', function(e) {
        if (!selected) return
        const { fileType, path, url } = selected.dataset
        switch (fileType) {
            case 'content':
                window.open(`\${url}?cmd=edit`, '_blank')
                break
        }
    })
    
    const deleteButton = document.getElementById('deleteButton')
    deleteButton.addEventListener('click', function(e) {
        if (!selected) return

        const target = selected
        const { fileType, path } = target.dataset

        if (!window.confirm(`Delete item.\n\${path}`)) return
        
        const promise = Promise.resolve()
        .then(() => {
            remainingController.incrementRemaining()
            switch (fileType) {
                case 'content':
                case 'file':
                    return client.removeFile(path)
                    
                case 'directory':
                    return client.removeDirectory(path)
                
                default:
                    throw new Error('Unknown File Type')
            }
        })
        .then(response => {
            if (selected == target) deselect()

            target.parentNode.removeChild(target)
        })
        .catch(error => {
            window.alert(`Remove Failed.\nPath:\${path}\n\${error}`)
        })
        .then(() => {
            remainingController.decrementRemaining()
        })
    })
    
    const moveButton = document.getElementById('moveButton')
    moveButton.addEventListener('click', function(e) {
        if (!selected) return

        const target = selected
        const { fileType, path } = target.dataset

        const newPath = window.prompt(`Move Path.\n\${path}\n->`, path)

        if (!newPath) return

        remainingController.incrementRemaining()

        client.rename(path, newPath)
        .then(response => {
            if (selected == target) deselect()

            target.parentNode.removeChild(target)
            switch(fileType) {
                case 'content':
                case 'file':
                    appendFileItem(newPath)
                    break

                case 'directory':
                    appendDirectoryItem(newPath)
                    break
            }
        })
        .catch(error => {
            window.alert(`Move Failed.\n\${path}\n->\n\${newPath}\n\${error}`)
        })
        .then(() => {
            remainingController.decrementRemaining()
        })

    })

    function appendFileItem(path) {
        const extension = path.split('.').pop()

        if (extension == 'content') {
            const old = contentList.querySelector(`[data-path='\${path}']`)
            if (old) old.parentNode.removeChild(old)
            
            if (!containsIn(directoryPath, path)) return
            const card = createContentCard(path)
            contentList.appendChild(card)
        } else {
            const old = fileList.querySelector(`[data-path='\${path}']`)
            if (old) old.parentNode.removeChild(old)
            
            if (!containsIn(directoryPath, path)) return
            const item = createFileItem(path, ['jpg', 'jpeg', 'png', 'bmp'].includes(extension.toLowerCase()))
            fileList.appendChild(item)
        }
    }

    function appendDirectoryItem(path) {
        const old = directoryList.querySelector(`[data-path='\${path}']`)
        if (old) old.parentNode.removeChild(old)

        if (!containsIn(directoryPath, path)) return
        const item = createDirectoryItem(path)
        directoryList.appendChild(item)
    }

    function createContentCard(path) {
        const basename = path.split('/').pop()
        const href = pathToURI(removeExtension(path))
        const card = document.createElement('div')
        card.dataset.path = path
        card.dataset.fileType = 'content'
        card.innerHTML = `
            <div class='card-item'>
                <div class='inner'><a class='title' href='\${href}'>\${removeExtension(basename)}</a></div>
                <div class='footer'>\${basename}</div>
                <a class='hover-link' href='\${href}'></a>
            </div>`
        setupFileElement(card)
        return card
    }

    function createFileItem(path, isImage=false) {
        const basename = path.split('/').pop()
        const href = pathToURI(path)
        const item = document.createElement('div')
        const img = isImage ? `<img src='\${href}' loading='lazy'>` : ''
        item.dataset.path = path
        item.dataset.fileType = 'file'
        item.innerHTML = `
            <a class='file' href='\${href}'>
                <div class='thumbnail'>\${img}</div>
                <div class='file-title'>
                    <div class='icon file-icon'></div>
                    <div class='name'>\${basename}</div>
                </div>
            </a>
        `
        setupFileElement(item)
        return item
    }

    function createDirectoryItem(path) {
        const basename = path.split('/').pop()
        const href = pathToURI(path)
        const item = document.createElement('div')
        item.dataset.path = path
        item.dataset.fileType = 'directory'
        item.innerHTML = `
            <a class='directory' href='\${href}'>
                <div class='icon folder-icon'></div>
                <div class='name'>\${basename}</div>
            </a>
        `
        setupFileElement(item)
        return item
    }

    function removeExtension(path) {
        return path.replace(/\.[^/.]+$/, '')
    }

    // Master/Contents/Root -> /ContentsPlanet/Master/Root
    function pathToURI(path) {
        return `${rootURI}/\${path.replace(/^([^\/]*)(\/Contents)(\/.*)?/, '$1$3')}`
    }
    
    function containsIn(dir, path) {
        return path.split('/').slice(0, -1).join('/') === dir
    }
</script>
    ";
}

$vars['contentBody'] = $body;

$vars['canonialUrl'] = (empty($_SERVER["HTTPS"]) ? "http://" : "https://") .
    $_SERVER["HTTP_HOST"] . $vars['subURI'] . '?hl=' . $vars['layerName'];

// END measuring build time ---
$swBuild->Stop();

$vars['pageBuildReport']['times']['build'] = [
    'displayName' => 'Build Time',
    'ms' => $swBuild->Elapsed() * 1000
];


require(FRONTEND_DIR . '/viewer.php');


function CreateNavi($parents, $current, $children, $language)
{
    $navi = '<nav class="navi"><ul>';

    $parentIndex = -1;
    if (!empty($parents)) {
        $parentIndex = count($parents) - 1;
        $stack[] = $parents[$parentIndex];
    } else {
        $stack[] = $current;
    }

    while (!is_null($path = array_pop($stack))) {
        if ($path === true) {
            $navi .= '</ul>';
            continue;
        }

        // WARNING: Must add "/" at end of path.
        //  If not, the following case leads a wrong result.
        //  current: "/Master/Contents/WebTool"
        //  path   : "/Master/Contents/Web"
        //  "current" is not in "path", but it returns true.
        if (strpos("${current}/", "{$path}/") === 0) {
            $navi .= '<li><a class="selected" href="'
                . CVUtils\CreateDirectoryHREF("/${path}", $language) . '">'
                . basename($path) . '</a>';
        } else {
            $navi .= '<li><a href="'
                . CVUtils\CreateDirectoryHREF("/${path}", $language) . '">'
                . basename($path) . '</a>';
        }

        if ($parentIndex >= 0 && $path === $parents[$parentIndex]) {
            $result = GetFilesAndSubDirs($path);

            // ディレクトリを抜けたときの印
            $stack[] = true;

            $stack = array_merge($stack, array_reverse($result['subDirs']));

            $navi .= '<ul>';

            $parentIndex--;
        }

        if ($path === $current) {
            // ディレクトリを抜けたときの印
            $stack[] = true;

            $stack = array_merge($stack, array_reverse($children));

            $navi .= '<ul>';
        }
    }
    $navi .= '</ul></nav>';
    return $navi;
}

function GetFilesAndSubDirs($directoryPath)
{
    $subDirs = [];
    $files = [];

    $cdir = scandir(CONTENTS_HOME_DIR . '/' . $directoryPath);
    foreach ($cdir as $c) {
        if (!in_array($c, array(".", ".."))) {
            if (is_dir(CONTENTS_HOME_DIR . '/' . $directoryPath . '/' . $c)) {
                $subDirs[] = $directoryPath . '/' . $c;
            } else {
                $files[] = $directoryPath . '/' . $c;
            }
        }
    }

    return ['subDirs' => $subDirs, 'files' => $files];
}
