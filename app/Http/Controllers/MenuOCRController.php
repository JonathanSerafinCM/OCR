<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use thiagoalessio\TesseractOCR\TesseractOCR;
use App\Models\Menu;

class MenuOCRController extends Controller
{
    public function processMenu(Request $request)
    {
        $request->validate([
            'menu_file' => 'required|file|mimes:jpg,png,pdf'
        ]);

        $file = $request->file('menu_file');
        $path = $file->store('temp');
        
        // Si es PDF, convertir a imagen
        if ($file->getClientOriginalExtension() === 'pdf') {
            // Usar poppler-utils para convertir PDF a imagen
            $imagePath = storage_path('app/temp/output.png');
            exec("pdftoppm -png {$path} {$imagePath}");
            $path = $imagePath;
        }

        // Procesar con Tesseract
        $text = (new TesseractOCR($path))
            ->lang('spa')
            ->run();

        // Procesar el texto extraído
        $menuItems = $this->parseMenuText($text);

        // Guardar en la base de datos
        foreach ($menuItems as $item) {
            Menu::create($item);
        }

        return response()->json(['message' => 'Menu processed successfully']);
    }

    private function parseMenuText($text)
    {
        $items = [];
        $lines = explode("\n", $text);
        $currentCategory = '';
        
        foreach ($lines as $line) {
            // Detectar categorías (generalmente en mayúsculas)
            if (preg_match('/^[A-ZÁÉÍÓÚÑ\s]{3,}$/', trim($line))) {
                $currentCategory = trim($line);
                continue;
            }

            // Detectar platos y precios
            if (preg_match('/^(.+?)\s*(\d{1,3}(?:,\d{2})?(?:\.\d{2})?)\s*€?$/', $line, $matches)) {
                $items[] = [
                    'category' => $currentCategory,
                    'dish_name' => trim($matches[1]),
                    'price' => (float) str_replace(',', '.', $matches[2]),
                    'description' => '',
                    'special_notes' => ''
                ];
            }
        }

        return $items;
    }
}
