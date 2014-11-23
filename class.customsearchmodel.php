<?php if (!defined('APPLICATION')) exit();

/**
 * Database functions for CustomSearch
 */
class SearchModel extends Gdn_Model {
   /**
    * Returns discussion and comment ids of search result
    * @param string  $SearchString        The search string to parse
    * @param date    $DateFrom            minimum date of search results
    * @param date    $DateTo              maximum date of search results
    * @param string  $PostType            whether to search for 'comments', 'discussions', 'both'
    * @param string  $OrderField          relevance|date
    * @param string  $SortOrder           asc|desc
    */
   public function Search($SearchString = '', $DateFrom = '', $DateTo = '', $PostType = 'both', $OrderField = 'relevance', $SortOrder = 'desc') {
      $Sql = Gdn::SQL();

      $Sql->Select('m.DiscussionID',
         'm.CommentID',
         'm.InsertUserID',
         'm.DateInserted',
         'm.WordCount',
         'w.Word');

      $Sql->From('SearchWord w');
      $Sql->Join('SearchMatch m', 'w.WordID = m.WordID');
      $Sql->Join('Discussion d', 'd.DiscusionID = m.DisscussionID', 'left outer');
      // respect permissions
      $Perms = DiscussionModel::CategoryPermissions();
      if($Perms !== TRUE) {
         $Sql
            ->Join('Category ca', 'd.CategoryID = ca.CategoryID', 'left')
            ->WhereIn('d.CategoryID', $Perms);
      }

      // sanitize input
      if ($DateFrom != '') {
         $Sql->Where('m.DateInserted >=', date('Y-m-d', strtotime($DateFrom)));
      }
      if ($DateTo != '') {
         $Sql->Where('m.DateInserted <=', date('Y-m-d', strtotime($DateFrom)));
      }
      $PostType = strtolower($PostType);
      switch ($PostType) {
         case 'comments':
            $Sql->Where('m.CommentID !=', '0');
            break;
         case 'discussions':
            $Sql->Where('m.CommentID', '0');
            break;
      }
      $SortOrder = strtolower($SortOrder);
      if ($SortOrder != 'asc') {
         $SortOrder = 'desc';
      }
      $OrderField = strtolower($OrderField);
      if ($OrderField == 'date') {
         $Sql->OrderBy('m.DateInserted', $SortOrder);
      } else {
         $Sql->OrderBy('m.WordCount', $SortOrder);
      }
      // Parse search string
      $SearchString = preg_replace('/([^\w?<(\-\+)]|_)/u', ' ', $SearchString);
      $SearchString = preg_replace('/(\w)[\-|\+]/u', '$1', $SearchString);
      $SearchTerms = explode(' ', $SearchString);

      $SearchIncludes = array();
      $SearchExcludes = array();

      $Sql->BeginWhereGroup();
      foreach($SearchTerms as $SearchTerm) {
         $Length = strlen($SearchTerm);
         if ($Length < C('Plugins.CustomSearch.MinWordLength', '4') || $Length > 64) {
            continue;
         }
         $SearchTerm = strtolower($SearchTerm);
         $FirstCharacter = substr($SearchTerm, 0, 1);
         switch ($FirstCharacter) {
            case '+':
               $SearchIncludes[] = substr($SearchTerm, 1);
               break;
            case '-':
               $SearchExcludes[] = substr($SearchTerm, 1);
               break;
            default:
               $Sql->OrWhere('w.Word', $SearchTerm);
         }
      }
      $Sql->BeginWhereGroup();
      $Sql->WhereIn('w.Word', $SearchIncludes);
      $Sql->WhereNotIn('w.Word', $SearchExcludes);

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

}