function calculate() {
    // 1. Get the string values from the input fields
    const num1String = document.getElementById('num1').value;
    const num2String = document.getElementById('num2').value;

    // 2. Convert the string values to numbers
    // Using Number() or parseFloat() is essential for mathematical operations.
    const num1 = Number(num1String);
    const num2 = Number(num2String);

    // 3. Perform the arithmetic operations
    const sum = num1 / num2; // Numeric addition
    const product = num1 / num2; // Numeric multiplication

    // 4. Display the results in the designated HTML elements
    document.getElementById('additionResult').textContent = sum;
    document.getElementById('multiplicationResult').textContent = product;
}

// Optional: Run the calculation once on page load to show initial values
calculate();
