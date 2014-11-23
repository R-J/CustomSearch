<?php if (!defined('APPLICATION')) exit(); ?>
<h1><?php echo $this->Data['Title']; ?></h1>
<div class="Info">
   <?php echo $this->Data['Description']; ?>
</div>
<div class="FormWrapper">
   <?php
      echo $this->Form->Open();
      echo $this->Form->Errors();
   ?>
   <div class="P">
      <?php
         echo $this->Form->Label('Minimum Word Length', 'MinWordLength');
         echo $this->Form->TextBox('MinWordLength');
      ?>
      </div>
   </div>
      
   <div class="P">
      <?php
         echo $this->Form->Label('Stop Words', 'StopWords');
         echo $this->Form->TextBox('StopWords');
      ?>
   </div>
   <hr />
   <?php echo $this->Form->Close('Save'); ?>
      
   <hr />
</div>
   