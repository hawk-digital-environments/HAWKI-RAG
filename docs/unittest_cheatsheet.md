# Python RAG Unit Test Cheatsheet

Run all tests:
```bash
python -m unittest discover -s python_rag/tests -p "test_*.py"
```

Run a single test module:
```bash
python -m unittest python_rag.tests.test_triplet_fallback
```

Run with verbose output:
```bash
python -m unittest -v python_rag.tests.test_triplet_fallback
```
