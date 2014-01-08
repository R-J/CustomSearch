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
	'RegisterPermissions' => FALSE,
	'Author' => 'Robin'
);

class CustomSearchPlugin extends Gdn_Plugin {
   public function Setup() {
      $this->Structure();
      if (!C('Plugins.CustomSearch.StopWords')) {
         SaveToConfig('Plugins.CustomSearch.StopWords', 'this|that');
      }
      if (!C('Plugins.CustomSearch.MinWordLength')) {
         SaveToConfig('Plugins.CustomSearch.MinWordLength', '4');
      }
   }
   private function Structure() {
      $Structure = Gdn::Structure();

      $Structure->Table('CSWordList')
         ->PrimaryKey('WordID', 'int(11)')
         ->Column('Word', 'varchar(255)', FALSE, 'unique')
         ->Engine('InnoDB')
         ->Set(FALSE, FALSE);
         
      $Structure->Table('CSOccurance')
         ->Column('DiscussionID', 'int(11)', '0')
         ->Column('CommentID', 'int(11)', '0')
         ->Column('InsertUserID', 'int(11)', FALSE)
         ->Column('DateInserted', 'datetime', FALSE)
         ->Column('WordID', 'int(11)', FALSE)
         ->Column('OccuranceCount', 'int(11)', '1')
         ->Engine('InnoDB')
         ->Set(FALSE, FALSE);
      $Px = Gdn::Database()->DatabasePrefix;
      Gdn::SQL()->Query("ALTER TABLE {$Px}CSOccurance ADD UNIQUE INDEX(DiscussionID, CommentID, WordID)");

      $Structure->Table('CSPostWord') // will an index on both columns be good for speed?
         ->Column('WordID', 'int(11)', '0')
         ->Column('Word', 'varchar(255)', FALSE)
         ->Engine('InnoDB')
         ->Set(FALSE, FALSE);
   }
   
   // index discussions after they have been saved
   public function DiscussionModel_AfterSaveDiscussion_Handler($Sender) {
      $Fields = $Sender->EventArguments['Fields'];
      $this->IndexText(
         $Fields['Body'],
         $Sender->EventArguments['DiscussionID'],
         0,
         $Fields['InsertUserID'],
         $Fields['DateInserted']
      );
   }
   // clean search index if discussion is deleted
   public function DiscussionModel_DeleteDiscussion_Handler($Sender) {
      Gdn::SQL()->Delete('CSOccurance', array('DiscussionID' => $Sender->EventArguments['DiscussionID']));
   }

   // index comments after they have been saved
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
   // clean search index if comment is deleted
   public function CommentModel_DeleteComment_Handler($Sender) {
      Gdn::SQL()->Delete('CSOccurance', array('DiscussionID' => $Sender->EventArguments['Discussion']->DiscussionID, 'CommentID' => $Sender->EventArguments['CommentID']));
   }

   // maybe this will lead to a timeout :-(
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

/* test function */
   public function DiscussionController_AfterCommentBody_Handler($Sender) {
return;
      $Post = $Sender->EventArguments['Comment']->Body;
      if ($Post == '') {
         $Post = $Sender->EventArguments['Discussion']->Body;
         $DiscussionID = $Sender->EventArguments['Discussion']->DiscussionID;
         $CommentID = 0;
         $InsertUserID = $Sender->EventArguments['Discussion']->InsertUserID;
         $DateInserted = $Sender->EventArguments['Discussion']->DateInserted;
      } else {
         $DiscussionID = 0;
         $CommentID = $Sender->EventArguments['Comment']->CommentID;
         $InsertUserID = $Sender->EventArguments['Comment']->InsertUserID;
         $DateInserted = $Sender->EventArguments['Comment']->DateInserted;
      }
      $this->IndexText($Post, $DiscussionID, $CommentID, $InsertUserID, $DateInserted);
   }
   
   private function IndexText($Post, $DiscussionID = 0, $CommentID = 0, $InsertUserID, $DateInserted) {
      $Px = Gdn::Database()->DatabasePrefix;
      $Sql = Gdn::SQL();

      // to prevent double indexing, first try to delete regarding rows from table
      $Sql->Delete('CSOccurance', array('DiscussionID' => $DiscussionID, 'CommentID' => $CommentID));
      
      // prepare temp table by deleting its contents
      $Sql->Truncate('CSPostWord');
      
      // prepare wordlist
      // make post all lowercase
      $WordList = strtolower($Post);
      // strip html tags (don't know if this makes sense)
      // $WordList = strip_tags($WordList);
      // delete everything but letters, numbers and underscores
      $WordList = preg_replace('/([^\w])/u', ' ', $WordList);
      // prepare list of stopwords
      $StopWords = C('Plugins.CustomSearch.StopWords');
      if (substr($StopWords, 0, 1) != '|')
         $StopWords = '|'.$StopWords;
      if (strlen($StopWords) == 1)
         $StopWords = '';
      // clear stop words, underscores and small words. small = MinWordLength - 1
      $WordList = preg_replace('/\b(\w{0,'.(C('Plugins.CustomSearch.MinWordLength', '4') - 1).'}'.$StopWords.'|_)\b/u', ' ', $WordList);
      // compress multiple spaces to one
      $WordList = trim(preg_replace('/\s+/', ' ', $WordList));
      // no keywords => nothing to do!
      if ($WordList == '') {
         return;
      }
      // Format word list to use in sql insert command
      $WordList = "('".str_replace(' ', "'),('", $WordList)."')";
      // insert all words into temp table
      $Sql->Insert('CSPostWord', array('Word'), 'VALUES '.$WordList);
      
      // insert "new" words into wordlist
      $NewWordsQuery = "INSERT INTO {$Px}CSWordList (Word) SELECT DISTINCT Word FROM {$Px}CSPostWord WHERE Word NOT IN (SELECT Word FROM {$Px}CSWordList)";
      $Sql->Query($NewWordsQuery);
      
      // add wordids into temp table
      $AddWordIDQuery = "UPDATE {$Px}CSPostWord SET WordID = (SELECT WordID FROM {$Px}CSWordList WHERE {$Px}CSPostWord.Word = {$Px}CSWordList.Word)";
      $Sql->Query($AddWordIDQuery);
      
      // add count occurance info to table
      $CountQuery = "INSERT INTO {$Px}CSOccurance (DiscussionID, CommentID, InsertUserID, DateInserted, WordID, OccuranceCount) ";
      $CountQuery .= "SELECT {$DiscussionID}, {$CommentID}, {$InsertUserID}, '{$DateInserted}', WordID, COUNT(WordID) FROM {$Px}CSPostWord GROUP BY WordID";
      $Sql->Query($CountQuery);
   }
   
   public function SearchModel_Search_Handler($Sender) {
// $this->ReIndexAll();
// return;   
      $Px = Gdn::Database()->DatabasePrefix;

      $Sender->Reset();
      // $Sender->AddSearch("select 1 as `Relavence`    , 98 as `PrimaryID`    , 'Pfusch' as `Title`    , 'Tästmän ;)' as `Summary`    , 'HTML' `Format`    , 1 as `CategoryID`    , concat('/discussion/comment/', 98, '/#Comment_', c.CommentID) as `Url`    , '2013-04-01' as `DateInserted`    , 6 as `UserID` from    V20188_Comment c where c.CommentID = 98");
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
   {$Px}CSOccurance o
   LEFT JOIN {$Px}Discussion d ON o.DiscussionID = d.DiscussionID
   LEFT JOIN {$Px}Comment c ON o.CommentID = c.CommentID
WHERE
   o.WordID IN (
      SELECT WordID FROM {$Px}CSWordList
      WHERE Word in ('newwordhere', 'admin')
   )
ORDER BY
   o.OccuranceCount DESC
   , o.DateInserted DESC
EOT;
      $Sender->AddSearch($Sql);
//      decho($Sql);
//      decho($Sender->EventArguments['Search']);
//      decho($Sender);
      
   }
}
