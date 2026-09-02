# ContentsPlanet

[English](./README.md) | 日本語

![ContentsPlanet](http://contentsviewer.work/Master/ContentsPlanet/Images/Logo.jpg)

ContentsPlanet は, 次の三つの特徴を持つWebコンテンツ管理システム(CMS)です.

* OS標準のファイルシステムを介した他システム(Git, FTP, GitHub, GitLab, OneDrive, Google Drive, ...) との連携
* アウトラインの視認性と可読性を考慮したエディタに依存しないコンテンツ記述軽量マークアップ言語
* ディレクトリを超えたコンテンツの検索性とトピックモデルに基づいたコンテンツ管理(自動タグ付け, 自動カテゴライズ, 関連提示)

## 特徴
### 他システムとの連携
本システムのコンテンツ管理は, OS標準のファイルシステムを基本とします.
一つのコンテンツは, 一つのテキストファイルであり, コンテンツの階層は, ディレクトリで表現されます.
コンテンツのメタ情報(タグやキャッシュ)は, アクセスベースで更新され, システムを通さないファイル変更であっても,
正しく動作します. 

OS標準のファイルシステムを基本とすることにより, ファイルシステムベースの他のシステム(Git, FTP, Github, Gitlab, OneDrive, Google Drive, ...)との連携を可能にし, システムを超えたコンテンツの管理を実現します.

![他システムとの連携](http://contentsviewer.work/Master/ContentsPlanet/Images/Integration.jpg)

### エディタに依存しないアウトライン記述
文章の読みやすさ, 書きやすさの向上には, アウトラインの視認性と可読性が重要であると考えます. 
本システムでは, コンテンツの記述に, プレーンテキストの段階でアウトラインの視認性と可読性を考慮した, インデントが文章の階層構造を表す軽量マークアップ言語を採用しています.

アウトラインの視認性と可読性を上げるために, エディタのアウトライン機能の有無に関係なく, すべての標準的なエディタで使えるコンテンツの書き方ができます.

![エディタに依存しないアウトライン記述](http://contentsviewer.work/Master/ContentsPlanet/Images/OutlineEditorFree.jpg)

### ディレクトリを超えたコンテンツ管理
本システムにおいて, コンテンツは, OS標準のファイルシステムにより, ディレクトリで管理されることになります. 
そこで問題になるのが, コンテンツの検索性とトピックによるコンテンツの管理です.

コンテンツの検索性の問題では, ディレクトリによってカテゴライズされていることで, カテゴリ名を分かっている人は, ほしい情報にたどり着ける一方, 分からない人には困難です.
また, コンテンツが複数のトピックが合わさって生成される(トピックモデル)と考えると, コンテンツが一元的に管理できるとは考えられず, どれを根にとるかで, 階層関係は変化します. 

そこで, 本CMSでは, 検索性の向上に, ディレクトリを超えて, 全コンテンツを対象にあいまい検索をかけることが可能です. 
また, トピックによる管理では, トピックによる自動タグ付けと, あるコンテンツと関連したトピックを持つコンテンツの提示, トピックによる自動カテゴライズを行えます. 本CMSでは, OS標準のファイルシステムによる, ディレクトリベースの管理でありつつも, ディレクトリを超えたコンテンツの管理を行います.

![ディレクトリを超えたコンテンツ管理](http://contentsviewer.work/Master/ContentsPlanet/Images/AcrossDirectories.jpg)

## 機能一覧
* ディレクトリ，コンテンツファイルベース管理
* キャッシュ利用による速いレスポンス
* コンテンツ表示と編集
* コンテンツ検索
* トピックによるコンテンツ管理
* ユーザごとのコンテンツ管理と非公開設定
* 読み/書きやすい文章作成支援フォーマット
* ローカリゼーションへの対応
* データベース(MySQL など)を使用しない
* SSL(TLS)を使用できない環境でのある程度のセキュリティ
* クラウドストレージサービス(GitHub，GitLab，Google Drive，OneDrive，...)との連携
* プラグインによる機能拡張
* システムを通さない変更の自動保存

## 対応環境
本CMSの対応環境は以下の通りです. 無料のレンタルサーバでも動くようにしています.

* Apache HTTP Server
* PHP 8.3 以上
* PHP 拡張モジュール
    * mbstring
    * openssl
    * fileinfo

## 活用事例
個人~中規模のコンテンツ(最大約1000コンテンツ)を管理することを想定しています.
以下の方にお勧めです.

* 個人利用での備忘録
* サークル, 研究室, プロジェクトなど中規模の情報共有

## アクセスゲート (ボット対策)

ContentsPlanetには, 高コストなエンドポイント(既定ではタグマップとそのAPI)を
自動化された大量アクセスから守る, ステートレスなクライアント検証ゲート
(`Module/AccessGate.php`)が用意されています. クライアントはブラウザ上で
小さなSHA-256計算(約1秒, 1日1回)を解くことで, 署名付き・IP紐付き・期限付きの
トークンを取得します. サーバ側の検証はリクエストあたりHMAC1回で,
クライアントごとの状態保存はありません.

ゲートは**既定で無効**(フェイルオープン)です. 有効にするには, 配置した
`ContentsPlanet.php` に以下を設定します.

```php
// 生成例:  openssl rand -hex 32
define('ACCESS_GATE_SECRET', '<ランダムなhex秘密鍵>');
define('ACCESS_GATE_POW_BITS', 16);        // +1するごとにクライアントの計算量が倍
define('ACCESS_GATE_TOKEN_TTL', 86400);    // トークン有効期間(秒)
define('ACCESS_GATE_PROTECTED_URIS', [':tagmap']);
```

実際の秘密鍵はコミットしないでください. 必要な `.htaccess` 規則は, 秘密鍵が
設定されていれば `index.php` が(管理ブロック `# BEGIN ContentsPlanet` 内に)
自動生成します. 秘密鍵を空にすると次のリクエストで規則も自動的に外れます.
`ACCESS_GATE_POW_BITS` を引き上げると, 発行済みトークンは即座に全て失効します.

関連設定として, タグマップのURL空間の上限があります(ゲートとは独立で,
上限を超えたリクエストは素の404になります).

```php
define('TAGMAP_MAX_DEPTH', 5);  // /:tagmap/ 以降の最大セグメント数
define('TAGMAP_MAX_WIDTH', 5);  // 1セグメント内の最大タグ数(カンマ区切り)
```

## ライセンス
以下のサードパーティーライブラリを除き, このプロジェクト下のすべてのスクリプトは, [BSD 3-Clause License](./LICENSE) に従います.

* Client/ace
    * BSD 3-Clause License
    * <https://github.com/ajaxorg/ace>
* Client/ace-diff
    * MIT License
    * <https://github.com/ace-diff/ace-diff>
* Client/syntaxhighlighter
    * MIT License or GNU General Public License (GPL) Version 3
    * <https://github.com/syntaxhighlighter/syntaxhighlighter>

## その他の情報
本CMSに関する, その他詳しい情報は, [ContentsPlanet](http://contentsviewer.work/Master/ContentsPlanet/ContentsPlanet) をご覧ください.
