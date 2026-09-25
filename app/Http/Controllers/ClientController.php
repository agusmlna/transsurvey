<?php
namespace App\Http\Controllers;
use App\Models\Client;
use Illuminate\Http\Request;
class ClientController {
 public function index(Request $r){$q=Client::withCount('invitations')->orderBy('name');if($r->filled('q'))$q->where(fn($x)=>$x->where('name','like','%'.$r->input('q').'%')->orWhere('project','like','%'.$r->input('q').'%'));return view('clients.index',['clients'=>$q->paginate(20)->withQueryString()]);}
 public function create(){return view('clients.form',['client'=>new Client(['active'=>true])]);}
 public function edit(Client $client){return view('clients.form',compact('client'));}
 public function store(Request $r){Client::create($this->data($r));return redirect()->route('clients.index')->with('success','Klien berhasil ditambahkan.');}
 public function update(Request $r,Client $client){$client->update($this->data($r));return redirect()->route('clients.index')->with('success','Data klien disimpan. Email pada undangan lama tidak berubah.');}
 private function data(Request $r){$v=$r->validate(['name'=>'required|string|max:255','contact'=>'required|string|max:255','email'=>'required|email|max:255','project'=>'required|string|max:255','active'=>'nullable|boolean']);$v['active']=$r->boolean('active');return $v;}
}
