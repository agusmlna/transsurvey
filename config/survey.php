<?php
return ['email_enabled'=>(bool) env('SURVEY_EMAIL_ENABLED',false),'reminder_days'=>max(1,(int) env('SURVEY_REMINDER_DAYS',3)),'max_reminders'=>max(0,(int) env('SURVEY_MAX_REMINDERS',3)),
 // "Survey completed" emails go to these addresses (comma separated). Empty: every admin user.
 'completion_recipients'=>array_values(array_unique(array_filter(array_map(fn($email)=>strtolower(trim($email)),explode(',',(string) env('SURVEY_COMPLETION_RECIPIENTS','gunawan@trans-cosmos.co.id,dean.maudy@trans-cosmos.co.id'))),fn($email)=>filter_var($email,FILTER_VALIDATE_EMAIL))))];
