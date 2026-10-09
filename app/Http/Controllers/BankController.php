<?php

namespace App\Http\Controllers;

use App\Services\DataTableService;

use App\Models\BankQuestion;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BankController
{
    public function index(Request $r, DataTableService $tables)
    {
        $q = BankQuestion::orderBy('category');
        if ($r->boolean('_table'))
            return $tables->respond($r, $q, 'bank');
        return view('bank.index', ['questions' => BankQuestion::orderBy('category')->paginate(20)]);
    }
    public function store(Request $r)
    {
        $v = $r->validate(['text' => 'required|string|max:2000', 'category' => 'required|string|max:100', 'type' => 'required|in:rating,choice,text', 'options' => 'nullable|string|max:5000']);
        $options = array_values(array_filter(array_map('trim', preg_split('/\R/', $v['options'] ?? '')), fn($s) => $s !== ''));
        if ($v['type'] === 'choice' && (count($options) < 2 || count($options) !== count(array_unique($options))))
            throw ValidationException::withMessages(['options' => __('Masukkan minimal dua pilihan unik.')]);
        $v['options'] = $v['type'] === 'choice' ? $options : [];
        $v['required'] = $r->boolean('required');
        BankQuestion::create($v);
        return back()->with('success', __('Pertanyaan disimpan.'));
    }
}
