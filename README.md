Documentación de la API de Menú con Recomendaciones Personalizadas
Esta documentación describe los cambios realizados en la API para implementar un sistema de recomendaciones personalizadas y cómo utilizarlos en un frontend.
1. Endpoints de la API:
Todos los endpoints protegidos requieren autenticación utilizando Sanctum (tokens de API). Asegúrate de incluir el token en el encabezado Authorization de tus solicitudes:
     Authorization: Bearer <your_api_token>

POST /menu/process: Procesa un archivo de menú (imagen o PDF) para extraer información de los platos.
Request:
menu_file: Archivo del menú (imagen JPG, PNG o PDF).
Response:
message: Mensaje de éxito o error.
items_count: Número de platos procesados.
items: Array de platos con información (nombre, precio, descripción, categoría, alérgenos, etc.).
raw_text (solo en desarrollo): Texto extraído mediante OCR.
personalized: Booleano que indica si se aplicaron recomendaciones personalizadas.


GET /menu/items: Obtiene todos los elementos del menú.
Request (opcional):
category: Filtra por categoría.
price_range: Filtra por rango de precios (ej. "10-20").
Response:
items: Array de platos con información, incluyendo la puntuación de recomendación (recommendation_score).
preferences_applied: Booleano que indica si se aplicaron preferencias del usuario.


GET /preferences/api: Obtiene las preferencias del usuario actual.
Response:
preferences: Preferencias del usuario (restricciones dietéticas, etiquetas favoritas, historial de pedidos).
common_restrictions: Restricciones dietéticas comunes entre todos los usuarios.
popular_categories: Categorías más populares entre todos los usuarios.
POST /preferences/update: Actualiza las preferencias del usuario.
Request:
dietary_restrictions: Array de restricciones dietéticas.
favorite_tags: Array de etiquetas favoritas (categorías).


Response: Redirecciona a la ruta 'preferencias' con un mensaje de estado.
POST /dish/view: Registra una vista de un plato.
Request:
dish_id: ID del plato.


Response:
message: Mensaje de éxito.
GET /menu/popular: Obtiene los platos más populares.
Request (opcional):
timeframe: Periodo de tiempo en horas (por defecto 24).
limit: Número máximo de platos a devolver (por defecto 5).


Response:
popular_dishes: Array de platos populares con información.
timeframe: Periodo de tiempo utilizado.
POST /menu/filter: Filtra los elementos del menú según los parámetros proporcionados, además actualiza las preferencias del usuario con la información enviada.
Request:
min_price: Precio mínimo.
max_price: Precio máximo.
category: Categoría.
restrictions: Array de restricciones dietéticas.
favorite_tags: Array de etiquetas favoritas.
Response:
filtered_items: Array de platos que cumplen con los filtros.
preferences_updated: Booleano que indica si las preferencias se actualizaron.
POST /dishes/{dish}/rate: Puntúa un plato.
Request:
rating: Puntuación (1-5).


Response:
message: Mensaje de éxito.
new_recommendations: Recomendaciones actualizadas después de la interacción.
GET /dishes/{dish}/rating: Obtiene la puntuación de un plato por el usuario actual y la puntuación media.
Response:
user_rating: Puntuación del usuario actual.
average_rating: Puntuación media del plato.
2. Consideraciones:
Manejo de Errores: Implementa un manejo de errores robusto en tu frontend para mostrar mensajes de error amigables al usuario en caso de fallos en las solicitudes a la API.
Autenticación: Asegúrate de que tu frontend maneje la autenticación correctamente y envíe el token de API con cada solicitud a los endpoints protegidos.
Actualizaciones en Tiempo Real: El ejemplo de Vue.js muestra cómo actualizar las recomendaciones en tiempo real después de una calificación. Adapta este enfoque para otros eventos, como la visualización de un plato, si es necesario.



