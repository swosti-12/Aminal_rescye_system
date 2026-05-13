import pandas as pd
import numpy as np
import pickle
from sklearn.tree import DecisionTreeClassifier
from sklearn.model_selection import train_test_split
from sklearn.preprocessing import LabelEncoder

def train_model():
    print("Loading dataset...")
    df = pd.read_csv('dataset.csv')
    
    # Preprocessing
    le_animal = LabelEncoder()
    le_health = LabelEncoder()
    le_injury = LabelEncoder()
    
    df['animal_type_encoded'] = le_animal.fit_transform(df['animal_type'])
    df['health_condition_encoded'] = le_health.fit_transform(df['health_condition'])
    df['injury_severity_encoded'] = le_injury.fit_transform(df['injury_severity'])
    
    X = df[['animal_type_encoded', 'age_months', 'health_condition_encoded', 'injury_severity_encoded']]
    y = df['adopted']
    
    print("Training Decision Tree Classifier...")
    model = DecisionTreeClassifier(random_state=42, max_depth=5)
    model.fit(X, y)
    
    print("Saving model and encoders...")
    with open('adoption_model.pkl', 'wb') as f:
        pickle.dump(model, f)
        
    with open('encoders.pkl', 'wb') as f:
        pickle.dump({
            'animal': le_animal,
            'health': le_health,
            'injury': le_injury
        }, f)
        
    print("Model training complete!")

if __name__ == '__main__':
    train_model()
