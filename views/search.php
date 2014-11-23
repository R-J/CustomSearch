<?php if (!defined('APPLICATION')) exit();

/**
 * TODO
 *
 * $this->Data('DateFields') = array('day', 'month', 'year')
 */
 ?>
<h1><?php echo T('CustomSearch.SearchForm.SearchTitle'); ?></h1>
<?php
// echo $this->Form->Open(array('Action' => Url('/vanilla/search'), 'id' => 'Form_CustomSearch'));
echo $this->Form->Open();
echo $this->Form->Errors();
?>
<ul>
   <li class="SearchString">
      <?php
         echo $this->Form->Label('CustomSearch.SearchForm.SearchString', 'SearchString');
         echo $this->Form->TextBox('SearchString');
      ?>
      <span><?php echo T('CustomSearch.SearchForm.SearchStringExplanation'); ?></span>
   </li>
   <ul>
      <li class="DateFrom">
         <?php
            echo $this->Form->Label('CustomSearch.SearchForm.DateFrom', 'DateFrom');
            echo $this->Form->Date('DateFrom', array('fields' => $this->Data('DateFields')));
         ?>
      </li>
      <li class="DateTo">
         <?php
            echo $this->Form->Label('CustomSearch.SearchForm.DateTo', 'DateTo');
            echo $this->Form->Date('DateTo', array('fields' => $this->Data('DateFields')));
         ?>
      </li>
   </ul>
   <li>
      <?php
         echo $this->Form->Label('Notes', 'Notes');
         echo $this->Form->TextBox('Notes');
      ?>
      <span>Optional</span>
   </li>
   <?php $this->FireEvent("AfterAddBanForm"); ?>
</ul>
<?php
// echo $this->Form->Button('CustomSearch.SearchForm.ButtonSearch', array('class' => 'Button Primary'));
echo $this->Form->Close('CustomSearch.SearchForm.ButtonSearch');