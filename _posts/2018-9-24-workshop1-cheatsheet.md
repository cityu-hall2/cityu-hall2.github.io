---
title:  "Python Cheatsheet"
categories: WorkshopPage
permalink: /workshop1/Cheatsheet
---
# Variable 
## Print hello world to the console
``` python
msg = "Hello world!" 
print(msg)
```
# List
## A list stores a series of items in a particular order. You access items using an index, or within a loop.
``` python
bikes = ['trek', 'redline', 'giant']
```
## Get the first item in a list
``` python
first_bike = bikes[0]
```
## Looping through a list
``` python
for bike in bikes: 
    print(bike)
```
**or you can do**
``` python
for i in range(len(bikes)):
    print(bike[i])
```
# Dictionary
## Dictionaries store connections between pieces of information. Each item in a dictionary is a key-value pair.
``` python
alien = {'color': 'green', 'points': 5}
```
## Accessing a value
``` python
print("The alien's color is " + alien['color'])
```
## Adding a new key-value pair
``` python
alien['x_position'] = 0
```
## Delete a key-value pair
``` python
key = 'color'
if key in alien:
    del alien[key]
```
## Get a list of keys
``` python
>>> alien.keys()
['color', 'x_position']
```
# Function
## Functions are named blocks of code, designed to do one specific job. Information passed to a function is called an argument, and information received by a function is called a parameter.
``` python
def greet_user(): 
    """Display a simple greeting.""" 
    print("Hello!") 

greet_user()
```
## Passing an argument
```python
def greet_user(username): 
    """Display a personalized greeting.""" 
    print("Hello, " + username + "!") 

greet_user('jesse')
```
## Returning a value
```python
def add_numbers(x, y): 
    """Add two numbers and return the sum.""" 
    return x + y 

sum = add_numbers(3, 5) 
print(sum)
```
# Working with files
## Your programs can read from files and write to files.
## Reading a file and storing it
```python
with open('hello.txt' ) as f: 
    content=f.read()
    print(content)
```
# If statement
## If statements allow you to examine the current state of a program and respond appropriately to that state. You can write a simple if statement that checks one condition, or you can create a complex series of if statements that idenitfy the exact conditions you're looking for.
```python
age = 17 
if age >= 18: 
    print("You're old enough to vote!") 
else: 
    print("You can't vote yet.")
```
## Testing if a value is in a list
```python
players = ['al', 'bea', 'cyn', 'dale'] 
if 'al' in players:
    print("al in players!")  

if 'al' not in players:
    print("al not in players!")      
```

# Some other syntax
## split a string to words list
```python
string='Life is short, you need Python'
wordlist=string.split()

>>> wordlist
['Life','is','short,','you','need','Python']
```
## randomly choose item in dictionary/list
### remember add ``` import random``` in the beginning of code
```python
alien = {'color': 'green', 'points': 5}
property=random.choice(alien)

>>> property
'color': 'green'
```