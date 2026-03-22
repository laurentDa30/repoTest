<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Service;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function index()
    {
        $services = Service::orderBy('category')->orderBy('sort_order')->get()->groupBy('category');
        return view('admin.services.index', compact('services'));
    }

    public function create()
    {
        $categories = Service::categories();
        return view('admin.services.form', compact('categories'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'category' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'price' => 'required|numeric|min:0',
            'duration' => 'required|integer|min:5',
            'sort_order' => 'integer|min:0',
            'active' => 'boolean',
        ]);

        $validated['active'] = $request->has('active');
        Service::create($validated);

        return redirect()->route('admin.services.index')->with('success', 'Service ajouté avec succès.');
    }

    public function edit(Service $service)
    {
        $categories = Service::categories();
        return view('admin.services.form', compact('service', 'categories'));
    }

    public function update(Request $request, Service $service)
    {
        $validated = $request->validate([
            'category' => 'required|string|max:255',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'price' => 'required|numeric|min:0',
            'duration' => 'required|integer|min:5',
            'sort_order' => 'integer|min:0',
            'active' => 'boolean',
        ]);

        $validated['active'] = $request->has('active');
        $service->update($validated);

        return redirect()->route('admin.services.index')->with('success', 'Service modifié avec succès.');
    }

    public function destroy(Service $service)
    {
        $service->delete();
        return redirect()->route('admin.services.index')->with('success', 'Service supprimé.');
    }
}
