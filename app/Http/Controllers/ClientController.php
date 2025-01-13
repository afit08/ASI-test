<?php

namespace App\Http\Controllers;

use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class ClientController extends Controller
{
    // Create
    public function store(Request $request)
    {
        $validatedData = $request->validate([
            'name' => 'required|string|max:250',
            'slug' => 'required|string|max:100|unique:clients',
            'is_project' => 'nullable|string|in:0,1',
            'self_capture' => 'nullable|string|max:1',
            'client_prefix' => 'required|string|max:4',
            'client_logo' => 'nullable|image', // Client logo is now optional
            'address' => 'nullable|string',
            'phone_number' => 'nullable|string|max:50',
            'city' => 'nullable|string|max:50',
        ]);
    
        try {
            // Check if a client logo is uploaded and process it
            if ($request->hasFile('client_logo') && $request->file('client_logo')->isValid()) {
                $file = $request->file('client_logo');
                // Store the file locally in the 'client_logos' directory
                $path = $file->store('client_logos', 'public');
            
                // Check if the file path is empty
                if (empty($path)) {
                    return response()->json(['error' => 'Failed to store client logo.'], 400);
                }
            
                // Get the URL of the stored file (relative to the storage folder)
                $validatedData['client_logo'] = asset('storage/' . $path);
            }
    
            // Create the client record
            $client = Client::create($validatedData);
    
            // Generate Redis cache
            Cache::put($client->slug, json_encode($client), now()->addDays(30));
    
            return response()->json($client, 201);
        } catch (\Exception $e) {
            // Catch any errors and return a response with the exception message
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    
    

    // Read
    public function show($slug)
    {
        // Check Redis first
        if (Cache::has($slug)) {
            return response()->json(json_decode(Cache::get($slug)));
        }

        // Fetch from database if not in Redis
        $client = Client::where('slug', $slug)->firstOrFail();

        // Cache it in Redis
        Cache::put($client->slug, json_encode($client), now()->addDays(30));

        return response()->json($client);
    }

    // Update
    public function update(Request $request, $slug)
    {
        // Fetch the client by its slug
        $client = Client::where('slug', $slug)->firstOrFail();
    
        // Validate incoming request data
        $validatedData = $request->validate([
            'name' => 'nullable|string|max:250',
            'is_project' => 'nullable|string|in:0,1',
            'self_capture' => 'nullable|string|max:1',
            'client_prefix' => 'nullable|string|max:4',
            'client_logo' => 'nullable|image', // Only validate image if provided
            'address' => 'nullable|string',
            'phone_number' => 'nullable|string|max:50',
            'city' => 'nullable|string|max:50',
        ]);
    
        // Check if a new client logo is uploaded
        if ($request->hasFile('client_logo')) {
            // Get the uploaded file
            $file = $request->file('client_logo');
    
            // Check if the file is valid
            if ($file->isValid()) {
                // Delete the old logo if it exists
                if ($client->client_logo) {
                    // Remove the old logo path (relative file path)
                    $oldFilePath = str_replace(asset('storage/'), '', $client->client_logo);
                    if (Storage::disk('public')->exists($oldFilePath)) {
                        // Delete the old file
                        Storage::disk('public')->delete($oldFilePath);
                    }
                }
    
                // Store the new file in 'public' disk under 'client_logos' folder
                $path = $file->store('client_logos', 'public');
    
                // If the file path is empty, return error
                if (empty($path)) {
                    return response()->json(['error' => 'Failed to store client logo.'], 400);
                }
    
                // Set the validated data for the new logo URL
                $validatedData['client_logo'] = asset('storage/' . $path);
            } else {
                return response()->json(['error' => 'Invalid client logo file.'], 400);
            }
        }
    
        // Update the client with the validated data, including the updated client logo if provided
        $client->update($validatedData);
    
        // Update cache with the new client data
        Cache::forget($client->slug);
        Cache::put($client->slug, json_encode($client), now()->addDays(30));
    
        // Return the updated client data
        return response()->json($client);
    }
    
    
    

    // Delete
    public function destroy($slug)
    {
        $client = Client::where('slug', $slug)->firstOrFail();

        // Soft delete (update deleted_at column)
        $client->delete();

        // Remove Redis cache
        Cache::forget($slug);

        return response()->json(['message' => 'Client deleted successfully']);
    }
}
