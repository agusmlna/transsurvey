<?php
return ['email_enabled'=>(bool) env('SURVEY_EMAIL_ENABLED',false),'reminder_days'=>max(1,(int) env('SURVEY_REMINDER_DAYS',3)),'max_reminders'=>max(0,(int) env('SURVEY_MAX_REMINDERS',3))];
