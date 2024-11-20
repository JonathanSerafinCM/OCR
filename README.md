# Documentación de la API de Menú con Recomendaciones Personalizadas

Esta documentación describe los cambios realizados en la API para implementar un sistema de recomendaciones personalizadas y cómo integrarlos en un frontend. 

## Tabla de Contenidos
1. [Requisitos de Autenticación](#requisitos-de-autenticación)  
2. [Endpoints de la API](#endpoints-de-la-api)  
   - [Procesar Menú](#procesar-menú)  
   - [Obtener Elementos del Menú](#obtener-elementos-del-menú)  
   - [Preferencias del Usuario](#preferencias-del-usuario)  
   - [Platos Populares](#platos-populares)  
   - [Filtrar Elementos del Menú](#filtrar-elementos-del-menú)  
   - [Calificar un Plato](#calificar-un-plato)  
3. [Consideraciones](#consideraciones)  

---

## Requisitos de Autenticación

Todos los endpoints protegidos requieren autenticación utilizando **Sanctum** (tokens de API). Incluye el token en el encabezado `Authorization` de tus solicitudes:

```
Authorization: Bearer <your_api_token>
```

---

## Endpoints de la API

### Procesar Menú
**POST** `/menu/process`  
Procesa un archivo de menú (imagen o PDF) para extraer información de los platos.  

**Request:**
- `menu_file`: Archivo del menú (imagen JPG, PNG o PDF).

**Response:**
```json
{
  "message": "Archivo procesado con éxito",
  "items_count": 10,
  "items": [
    { "nombre": "Plato 1", "precio": 10.99, "descripción": "Descripción", "categoría": "Entradas", "alérgenos": ["Gluten"] }
  ],
  "raw_text": "Texto extraído del archivo (solo en desarrollo)",
  "personalized": true
}
```

---

### Obtener Elementos del Menú
**GET** `/menu/items`  
Obtiene todos los elementos del menú, con soporte para filtros opcionales.  

**Request (opcional):**
- `category`: Filtra por categoría.  
- `price_range`: Filtra por rango de precios (ej. `10-20`).

**Response:**
```json
{
  "items": [
    { "nombre": "Plato 1", "precio": 10.99, "categoría": "Entradas", "recommendation_score": 4.5 }
  ],
  "preferences_applied": true
}
```

---

### Preferencias del Usuario
**GET** `/preferences/api`  
Obtiene las preferencias del usuario actual.

**Response:**
```json
{
  "preferences": {
    "dietary_restrictions": ["Sin gluten"],
    "favorite_tags": ["Postres"],
    "historial": ["Plato 1", "Plato 2"]
  },
  "common_restrictions": ["Vegano", "Sin lactosa"],
  "popular_categories": ["Entradas", "Postres"]
}
```

**POST** `/preferences/update`  
Actualiza las preferencias del usuario.  

**Request:**
- `dietary_restrictions`: Array de restricciones dietéticas.  
- `favorite_tags`: Array de etiquetas favoritas.  

**Response:**
Redirecciona a la ruta `preferencias` con un mensaje de estado.

---

### Platos Populares
**GET** `/menu/popular`  
Obtiene los platos más populares en un periodo de tiempo definido.  

**Request (opcional):**
- `timeframe`: Periodo de tiempo en horas (por defecto `24`).  
- `limit`: Número máximo de platos a devolver (por defecto `5`).  

**Response:**
```json
{
  "popular_dishes": [
    { "nombre": "Plato 1", "veces_visto": 15, "puntuación": 4.8 }
  ],
  "timeframe": 24
}
```

---

### Filtrar Elementos del Menú
**POST** `/menu/filter`  
Filtra los elementos del menú según los parámetros proporcionados y actualiza las preferencias del usuario.  

**Request:**
- `min_price`: Precio mínimo.  
- `max_price`: Precio máximo.  
- `category`: Categoría.  
- `restrictions`: Array de restricciones dietéticas.  
- `favorite_tags`: Array de etiquetas favoritas.  

**Response:**
```json
{
  "filtered_items": [
    { "nombre": "Plato 1", "precio": 10.99, "categoría": "Entradas" }
  ],
  "preferences_updated": true
}
```

---

### Calificar un Plato
**POST** `/dishes/{dish}/rate`  
Puntúa un plato y actualiza las recomendaciones.  

**Request:**
- `rating`: Puntuación (1-5).  

**Response:**
```json
{
  "message": "Puntuación registrada",
  "new_recommendations": [
    { "nombre": "Plato recomendado", "puntuación": 4.9 }
  ]
}
```

**GET** `/dishes/{dish}/rating`  
Obtiene la puntuación de un plato por el usuario actual y la puntuación media.  

**Response:**
```json
{
  "user_rating": 4,
  "average_rating": 4.5
}
```

---

## Consideraciones

1. **Manejo de Errores:**  
   Implementa un manejo de errores robusto para mostrar mensajes amigables al usuario en caso de fallos en las solicitudes a la API.

2. **Autenticación:**  
   Asegúrate de que el token de autenticación se envíe correctamente en cada solicitud a los endpoints protegidos.

3. **Actualizaciones en Tiempo Real:**  
   Adapta el enfoque de actualizaciones en tiempo real para eventos relevantes, como la calificación o visualización de un plato, utilizando tecnologías como Vue.js, React o similares.

--- 

**Nota:** Para dudas o sugerencias sobre esta documentación, contacta al equipo de desarrollo.
