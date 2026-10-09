<?php

// Laravel supplies the English validation messages; customize field labels here.
return [
    'attributes' => [
        'name' => 'company name', 'contact' => 'contact name', 'email' => 'email',
        'password' => 'password', 'project' => 'project', 'active' => 'active status',
        'title' => 'title', 'description' => 'introduction', 'status' => 'status',
        'starts_at' => 'start date', 'ends_at' => 'end date', 'is_template' => 'template option',
        'questions' => 'questions', 'questions.*.text' => 'question text',
        'questions.*.category' => 'category', 'questions.*.type' => 'question type',
        'questions.*.required' => 'required option', 'questions.*.options' => 'answer options',
        'text' => 'question', 'category' => 'category', 'type' => 'question type', 'options' => 'answer options',
        'survey_id' => 'questionnaire', 'client_id' => 'client', 'client_ids' => 'recipients',
        'client_ids.*' => 'recipient', 'assigned_to' => 'assignee', 'due_at' => 'due date',
        'notes' => 'resolution notes', 'version' => 'data version', 'from' => 'start date',
        'to' => 'end date', 'locale' => 'language', 'return_to' => 'return page',
        'source' => 'data source', 'answers' => 'answers', 'answers.*.value' => 'answer',
        'answers.*.comment' => 'comment', 'action' => 'action', 'access_code' => 'access code',
    ],
    'values' => ['status' => ['resolved' => 'resolved']],
];
