from flask import Flask, request, jsonify
from flask_cors import CORS
import pickle
import numpy as np
import math
import random

app = Flask(__name__)
CORS(app)

# Load adoption prediction model (train with model_trainer.py first)
try:
    with open('adoption_model.pkl', 'rb') as f:
        adoption_model = pickle.load(f)
    with open('encoders.pkl', 'rb') as f:
        encoders = pickle.load(f)
    print("Model loaded successfully")
except Exception as e:
    print(f"Warning: Model not loaded. {e}. Run model_trainer.py first.")
    adoption_model = None
    encoders = None

def haversine(lat1, lon1, lat2, lon2):
    R = 6371.0  # Earth radius in kilometers
    lat1, lon1, lat2, lon2 = map(math.radians, [lat1, lon1, lat2, lon2])
    dlat = lat2 - lat1
    dlon = lon2 - lon1
    a = math.sin(dlat / 2)**2 + math.cos(lat1) * math.cos(lat2) * math.sin(dlon / 2)**2
    c = 2 * math.asin(math.sqrt(a))
    distance = R * c
    return distance

@app.route('/predict-adoption', methods=['POST'])
def predict_adoption():
    if not adoption_model:
        return jsonify({'error': 'Model not trained on server side'}), 500
        
    data = request.json
    try:
        # Expected keys: animal_type, age, health_condition, injury_severity
        animal = encoders['animal'].transform([data['animal_type']])[0]
        health = encoders['health'].transform([data['health_condition']])[0]
        injury = encoders['injury'].transform([data['injury_severity']])[0]
        
        features = np.array([[animal, float(data['age']), health, injury]])
        prob = adoption_model.predict_proba(features)[0][1] # Probability of '1' (adopted)
        
        category = 'low'
        if prob > 0.7:
            category = 'high'
        elif prob > 0.4:
            category = 'medium'
            
        return jsonify({
            'probability': float(prob * 100),
            'category': category
        })
    except Exception as e:
        return jsonify({'error': str(e)}), 400

@app.route('/find-nearest', methods=['POST'])
def find_nearest():
    # Expects target lat/lon and a list of rescuers [{id, latitude, longitude}, ...]
    data = request.json
    target_lat = float(data['latitude'])
    target_lon = float(data['longitude'])
    rescuers = data.get('rescuers', [])
    
    if not rescuers:
        return jsonify({'error': 'No rescuers provided'})
        
    nearest = None
    min_dist = float('inf')
    
    for r in rescuers:
        dist = haversine(target_lat, target_lon, float(r['latitude']), float(r['longitude']))
        if dist < min_dist:
            min_dist = dist
            nearest = r
            nearest['distance_km'] = round(dist, 2)
            
    return jsonify({'nearest_rescuer': nearest})

@app.route('/analyze-image', methods=['POST'])
def analyze_image():
    try:
        # ✅ Check if image is received
        if 'image' not in request.files:
            return jsonify({'error': 'No image file provided'}), 400

        image = request.files['image']

        if image.filename == '':
            return jsonify({'error': 'Empty filename'}), 400

        # ✅ (Optional) Read image bytes (for real ML later)
        img_bytes = image.read()

        # --------------------------------------------------
        # 🤖 DUMMY AI LOGIC (for now)
        # Replace this later with OpenCV / ML model
        # --------------------------------------------------
        import random

        result = random.choice(['injured', 'not injured'])
        confidence = round(random.uniform(0.5, 0.95), 2)

        # --------------------------------------------------
        # ✅ RETURN FORMAT (VERY IMPORTANT)
        # --------------------------------------------------
        return jsonify({
            'result': result,
            'confidence': confidence
        })

    except Exception as e:
        return jsonify({'error': str(e)}), 500
    # In a real-world scenario, OpenCV or a CNN model (e.g. YOLO, ResNet) 
    # would process `request.files['image']` here.
    # For this academic representation, we simulate image classification output.
    
    severities = ['low', 'medium', 'high', 'critical']
    weights = [0.4, 0.3, 0.2, 0.1] 
    detected_severity = random.choices(severities, weights=weights)[0]
    
    # Priority logic based on severity
    priority = 'low'
    if detected_severity == 'critical':
        priority = 'urgent'
    elif detected_severity == 'high':
        priority = 'high'
    elif detected_severity == 'medium':
        priority = 'medium'
        
    return jsonify({
        'detected_severity': detected_severity,
        'assigned_priority': priority
    })

if __name__ == '__main__':
    app.run(port=5000, debug=True)
 