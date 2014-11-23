<?php if (!defined('APPLICATION')) exit();

$PluginInfo['CustomSearch'] = array(
   'Name' => 'Custom Search',
   'Description' => 'Custom Search creates an internal index of all discussions and comments and makes them searchable without the standard MySQL search routine',
   'Version' => '0.1',
   'RequiredApplications' => array('Vanilla' => '2.0.18'),
   'RequiredPlugins' => FALSE,
   'RequiredTheme' => FALSE,
   'MobileFriendly' => TRUE,
   'HasLocale' => TRUE,
   'RegisterPermissions' => array('Plugins.CustomSearch.View'),
   'SettingsUrl' => '/settings/customsearch',
   'SettingsPermission' => 'Garden.Settings.Manage',
   'Author' => 'Robin Jurinka'
);

/*
TODO
1. recreate word count
2. create settings view for stopword table
3. setting option to change db engine

      // Set table engine to InnoDB for Discussion and Comment
      Gdn::SQL()->Query("ALTER TABLE `{$Px}Discussion` DROP INDEX `TX_Discussion`");
      Gdn::SQL()->Query("ALTER TABLE `{$Px}Discussion` ENGINE = InnoDB");
      Gdn::SQL()->Query("ALTER TABLE `{$Px}Comment` DROP INDEX `TX_Comment`");
      Gdn::SQL()->Query("ALTER TABLE `{$Px}Comment` ENGINE = InnoDB");
*/

/**
 * Custom Search Plugin
 *
 * Creates a searchable index of all words of in postings
 *
 * Replaces the standard Vanilla search
 * Optionally set database engine of discussions and comments to InnoDB
 *
 * @since 0.1
 * @package CustomSearch
 */
class CustomSearchPlugin extends Gdn_Plugin {
   /**
    * Init config values
    *
    * @since 0.1
    * @package CustomSearch
    * @access public
    */
   public function Setup() {
      if (!C('Plugins.CustomSearch.MinWordLength')) {
         SaveToConfig('Plugins.CustomSearch.MinWordLength', '4');
      }
      $this->Structure();
      // sets route to search page
      $Router = Gdn::Router();
      $PluginPage = 'vanilla/customsearch$1';
      $NewRoute = '^customsearch(/.*)?$';
      if(!$Router->MatchRoute($NewRoute)) {
         $Router->SetRoute($NewRoute, $PluginPage, 'Internal');
      }
   }

   /**
    * Init config values
    * Creates additional tables
    *
    * @since 0.1
    * @package CustomSearch
    * @access private
    */
   private function Structure() {
      $Structure = Gdn::Structure();
      $Px = Gdn::Database()->DatabasePrefix;

      // Create table with all words for faster reference
      $Structure->Table('SearchWord')
         ->PrimaryKey('WordID', 'int(11)')
         ->Column('Word', 'varchar(64)', FALSE, 'unique')
         ->Engine('InnoDB')
         ->Set(FALSE, FALSE);

      // Create table with information of matching results
      $Structure->Table('SearchMatch')
         ->Column('WordID', 'int(11)', FALSE)
         ->Column('DiscussionID', 'int(11)', '0')
         ->Column('CommentID', 'int(11)', '0')
         ->Column('InsertUserID', 'int(11)', FALSE)
         ->Column('DateInserted', 'datetime', FALSE)
         ->Column('WordCount', 'int(11)', '1')
         ->Engine('InnoDB')
         ->Set(FALSE, FALSE);
      Gdn::SQL()->Query("ALTER TABLE `{$Px}SearchMatch` ADD UNIQUE INDEX(DiscussionID, CommentID, WordID)");

      // Create table for temporarely storing all words in a post
      $Structure->Table('SearchPostWord') // will an index on both columns be good for speed?
         ->Column('WordID', 'int(11)', '0')
         ->Column('Word', 'varchar(64)', FALSE)
         ->Engine('InnoDB')
         ->Set(FALSE, FALSE);

      // Create table for stop words
      $Structure->Table('SearchStopWord')
         ->PrimaryKey('StopWordID', 'int(11)')
         ->Column('StopWord', 'varchar(64)', FALSE, 'unique')
         ->Engine('InnoDB')
         ->Set(FALSE, FALSE);
   }

   /**
    * Hook after save of discussions and call IndexText function
    *
    * @since 0.1
    * @package CustomSearch
    * @access public
    * @param DiscussionModel $Sender
    */
   public function DiscussionModel_AfterSaveDiscussion_Handler($Sender) {
      $Fields = $Sender->EventArguments['Fields'];
      $this->IndexText(
         $Fields['Body'],
         $Sender->EventArguments['DiscussionID'],
         0, // CommentID
         $Fields['InsertUserID'],
         $Fields['DateInserted']
      );
   }

   /**
    * Hook after save of comments and call IndexText function
    *
    * @since 0.1
    * @package CustomSearch
    * @access public
    * @param CommentModel $Sender
    */
   public function CommentModel_AfterSaveComment_Handler($Sender) {
      $FormPostValues = $Sender->EventArguments['FormPostValues'];
      $this->IndexText(
         $FormPostValues['Body'],
         $FormPostValues['DiscussionID'],
         $Sender->EventArguments['CommentID'],
         $FormPostValues['InsertUserID'],
         $FormPostValues['DateInserted']
      );
   }

   /**
    *
    * Hook after deletion of discussions and call DeleteText for clean up
    *
    * @since 0.1
    * @package CustomSearch
    * @access public
    * @param DiscussionModel $Sender
    */
   public function DiscussionModel_DeleteDiscussion_Handler($Sender) {
      $this->DeleteText($Sender->EventArguments['DiscussionID']);
   }

   /**
    * Hook after deletion of comments and call DeleteText for clean up
    *
    * @since 0.1
    * @package CustomSearch
    * @access public
    * @param CommentModel $Sender
    */
   public function CommentModel_DeleteComment_Handler($Sender) {
      $this->DeleteText(
         $Sender->EventArguments['Discussion']->DiscussionID,
         $Sender->EventArguments['CommentID']
      );
   }

   /**
    * IndexText adds all words of the post to the search list
    *
    * @param string $Post        Body of Discussion or Comment
    * @param int $DiscussionID   DiscussionID
    * @param int $CommentID      CommentID. 0 for discussions
    * @param int $InsertUserID   UserID of post author
    * @param date $DateInserted  Original post date
    */
   private function IndexText($Post, $DiscussionID = 0, $CommentID = 0, $InsertUserID, $DateInserted) {
      $Px = Gdn::Database()->DatabasePrefix;
      $Sql = Gdn::SQL();

      // to prevent double indexing, first try to delete regarding rows from table
      $Sql->Delete('SearchMatch', array('DiscussionID' => $DiscussionID, 'CommentID' => $CommentID));

      // prepare temp table by deleting its contents
      $Sql->Truncate('SearchPostWord');

      // make post all lowercase
      $WordList = strtolower($Post);

      // strip html tags (don't know if this makes sense)
      // $WordList = strip_tags($WordList);

      // replace everything but letters and numbers with space and strip out words < MinWordLength and > 64 letters
      $WordList = preg_replace('/([^\w]|_|\b(\w{0,'.(C('Plugins.CustomSearch.MinWordLength', '4') - 1).'}|\w{65,})\b)/u', ' ', $WordList);

      // format wordlist for use in sql
      $WordList = "(0,'".preg_replace('/\s+/', "'),(0,'", trim($WordList))."')";

      // no keywords => nothing to do!
      if ($WordList == "(0,'')") {
         return;
      }

      // insert all words into temp table
      $Sql->Insert('SearchPostWord', array('WordID', 'Word'), 'VALUES '.$WordList);

      // insert "new" words into wordlist, exclude stop words!
      $NewWordsQuery = "INSERT INTO {$Px}SearchWord (Word) SELECT DISTINCT Word FROM {$Px}SearchPostWord WHERE Word NOT IN (SELECT Word FROM {$Px}SearchWord) AND Word NOT IN (SELECT StopWord FROM {$Px}SearchStopWord)";
      // add wordids into temp table
      $AddWordIDQuery = "UPDATE {$Px}SearchPostWord SET WordID = (SELECT WordID FROM {$Px}SearchWord WHERE {$Px}SearchPostWord.Word = {$Px}SearchWord.Word)";
      // add count occurance info to table
      $CountQuery = "INSERT INTO {$Px}SearchMatch (DiscussionID, CommentID, InsertUserID, DateInserted, WordID, WordCount) ";
      $CountQuery .= "SELECT {$DiscussionID}, {$CommentID}, {$InsertUserID}, '{$DateInserted}', WordID, COUNT(WordID) FROM {$Px}SearchPostWord WHERE WordID > 0 GROUP BY WordID";

      $Sql->Query($NewWordsQuery.'; '.$AddWordIDQuery.'; '.$CountQuery);
   }

   /**
    * Deletes complete discussion or single comment from SearchMatch table
    * @since 0.1
    * @package CustomSearch
    * @access private
    * @param integer $DiscussionID ID of discussion to delete
    * @param integer $CommentID    ID of comment to delete (optional). If omitted, complete discussion is deleted
    */
   private function DeleteText($DiscussionID, $CommentID = 0) {
      Gdn::SQL()->Delete('SearchMatch', array('DiscussionID' => $DiscussionID, 'CommentID' => $CommentID));
   }

   /**
    * Create index for each and every Discussion
    * That will lead most probably to a timeout for bigger boards...
    *
    * @since 0.1
    * @package CustomSearch
    * @access private
    */
   private function ReIndexAll() {
      $DiscussionModel = new DiscussionModel();
      $CommentModel = new CommentModel();
      $Discussions = $DiscussionModel->Get();
      foreach ($Discussions as $Discussion) {
         $DiscussionID = $Discussion->DiscussionID;
         $this->IndexText(
            $Discussion->Body,
            $DiscussionID,
            0,
            $Discussion->InsertUserID,
            $Discussion->DateInserted
         );
         $Comments = $CommentModel->Get($DiscussionID);
         foreach ($Comments as $Comment) {
            $this->IndexText(
               $Comment->Body,
               $DiscussionID,
               $Comment->CommentID,
               $Comment->InsertUserID,
               $Comment->DateInserted
            );
         }
      }
   }

// only for testing by now
// public function SearchModel_AfterBuildSearchQuery_Handler($Sender) {
   public function SearchModel_Search_Handler($Sender) {
return;      
// $this->ReIndexAll();
// return;   
// decho($Sender->Sql);

      $Sender->Reset();

      $Px = Gdn::Database()->DatabasePrefix;
      $Sql = <<<EOT
SELECT
     o.OccuranceCount AS `Relavence`    
   , CASE WHEN o.CommentID = 0 THEN o.DiscussionID ELSE o.CommentID END AS `PrimaryID`
   , d.Name AS `Title`
   , CASE WHEN o.CommentID = 0 THEN d.Body ELSE c.Body END AS `Summary`
   , CASE WHEN o.CommentID = 0 THEN d.Format ELSE c.Format END AS `Format`
   , d.CategoryID AS `CategoryID`
   , CASE WHEN o.CommentID = 0 THEN
         CONCAT('/discussion/', o.DiscussionID)
      ELSE
         CONCAT('/discussion/comment/', o.CommentID, '/#Comment_', o.CommentID)
      END AS `Url`
   , o.DateInserted AS `DateInserted`
   , o.InsertUserID AS `UserID`
FROM
   {$Px}SearchMatch o
   LEFT JOIN {$Px}Discussion d ON o.DiscussionID = d.DiscussionID
   LEFT JOIN {$Px}Comment c ON o.CommentID = c.CommentID
WHERE
   o.WordID IN (
      SELECT WordID FROM {$Px}SearchWord
      WHERE Word in ('newwordhere', 'admin')
   )
ORDER BY
   o.OccuranceCount DESC
   , o.DateInserted DESC
EOT;
      $Sender->AddSearch($Sql);
   }



   /**
    * Shows search screen, parses search string and returns search results
    *
    * @since 0.1
    * @package CustomSearch
    * @access public
    * @param VanillaController $Sender
    */
   public function VanillaController_CustomSearch_Create($Sender) {
      $Sender->Permission('Plugins.CustomSearch.View');

      $Sender->MasterView = 'default';

      $Sender->ClearCssFiles();
      $Sender->AddCssFile('style.css');
      $Sender->AddCssFile('customsearch.css', 'plugins/CustomSearch');

      $Sender->AddModule('UserBoxModule');
      $Sender->AddModule('CategoriesModule');
      $Sender->AddModule('BookmarkedModule');
      $Sender->AddModule('GuestModule');
      $Sender->AddModule('NewDiscussionModule');
      $Sender->AddModule('DiscussionFilterModule');
      $Sender->AddModule('SignedInModule');
      $Sender->AddModule('CategoryFollowModule');
      $Sender->AddModule('DraftsModule');
      $Sender->AddModule('CategoryModeratorsModule');
      $Sender->AddModule('Ads');

      $Sender->SetData('Breadcrumbs', array(array('Name' => T('CustomSearch.SearchForm.BreadCrumb'), 'Url' => '/customsearch')));
      $Sender->SetData('DateFields', array('day', 'month', 'year'));

      $Validation = new Validation();
      $Sender->Form->SetModel();

      if ($Sender->Form->IsPostBack()) {
         decho('process results');
      }
      
      $Sender->Render('search', '', 'plugins/CustomSearch');
   }

   /**
    * Function search gets the search results for users searches
    *
    * @param string $UserName    search for author; 0 every user
    * @param bool $Discussions   search in discussions
    * @param bool $Comments      search in comments
    * @param array $CategoryIDs  categories to search; 0 = every category
    * @param date $DateBegin     restrict  search results by date
    * @param date $DateEnd       restrict  search results by date
    * @param string $Search      search string
    */
   private function Search($Username = '', $Discussions = TRUE, $Comments = TRUE, $CategoryIDs = array(0), $DateBegin = 0, $DateEnd = 0, $Search = '') {
      $Sql = Gdn::SQL();

      // respect permissions
      $Perms = DiscussionModel::CategoryPermissions();
      if($Perms !== TRUE) {
         $Sql
            ->Join('Category ca', 'd.CategoryID = ca.CategoryID', 'left')
            ->WhereIn('d.CategoryID', $Perms);
      }

      $Sql->From('SearchWord w');
      $Sql->Join('SearchMatch m', 'w.WordID = m.WordID');
      $Sql->Join('Discussion d', 'd.DiscusionID = m.DisscussionID', 'left outer');
      
      if ($Username != '') {
         $User = UserModel::GetByUsername($Username);
         $Sql->Where('m.InsertUserID', $User->UserID);
      }
      if (!$Discussions) {
         $Sql->Where('m.CommentID >', '0');
      }
      if (!$Comments) {
         $Sql->Where('m.CommentID', '0');
      }
      if ($CategoryIDs != array(0)) {
         $Sql->Where('d.CategoryID', $CategoryIDs);
      }
      if($DateBegin != 0) {
         $Sql->Where('m.DateInserted >=', $DateBegin);
      }
      if($DateEnd != 0) {
         $Sql->Where('m.DateInserted <=', $DateEnd);
      }

      $Sql->Select('m.DiscussionID, m.CommentID, m.InsertUserID');
      $Sql->OrderBy('WordCount', 'Desc');
   }

   public function SettingsController_CustomSearch_Create($Sender) {
      $Sender->Permission('Garden.Settings.Manage');

      $Sender->AddSideMenu('settings/Custom Search');
      $Sender->Title(T('Settings Custom Search'));
      $Sender->SetData('Description', T('CustomSearchDescription', 'Custom Search replaces the standard Vanilla search.<br />Every posting is stripped down to letters and numbers. You can specify stop words that shouldn\'t be searched for and the minimum letters a word must have to be indexed.'));
      $Form = $Sender->Form;

      if ($Sender->Form->AuthenticatedPostBack() != FALSE) {
         // save!
         $Form->ValidateRule('MinWordLength', 'ValidateRequired'); // ..., CustomError ='')
         $Form->ValidateRule('MinWordLength', 'ValidateInteger');
//         $Form->ValidateRule('StopWords', 'regex:/\w+(,\w+)*/u', T('Please enter a list of words, separated with comma, without any whitspaces'));
         $Form->ValidateRule('StopWords', 'regex:/\w+(,\w+)*/u', T('Please enter a list of words, separated with comma, without any whitspaces'));
         SaveToConfig('Plugins.CustomSearch.MinWordLength', $Form->GetFormValue('MinWordLength'));
         $Sender->StatusMessage = T('Saved');
         /*
         if ($Sender->Form->Save() != FALSE) {
            
         }
         */
      } else {
         $Form->SetData(array(
            'MinWordLength' => C('Plugins.CustomSearch.MinWordLength', '4'),
            'StopWords' => 'ja, amen'
         ));
      }

      $Sender->Render(parent::GetView('settings.php'));
   }
}
