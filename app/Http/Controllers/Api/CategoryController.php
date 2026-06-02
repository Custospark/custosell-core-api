<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CategoryRequest;
use App\Http\Resources\CategoryCollection;
use App\Http\Resources\CategoryResource;
use App\Services\Contracts\CategoryServiceInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function __construct(
        protected CategoryServiceInterface $categoryService,
    ) {}

    public function index(Request $request): CategoryCollection
    {
        $businessId = $request->user()->business_id;
        return new CategoryCollection($this->categoryService->getAll($businessId));
    }

    public function show(int $id): CategoryResource
    {
        $category = $this->categoryService->getById($id);
        if (!$category) {
            abort(404, 'Category not found');
        }
        return new CategoryResource($category);
    }

    public function store(CategoryRequest $request): JsonResponse
    {
        $businessId = $request->user()->business_id;
        $category = $this->categoryService->create($businessId, $request->validated());
        return response()->json(new CategoryResource($category), 201);
    }

    public function update(CategoryRequest $request, int $id): CategoryResource
    {
        $category = $this->categoryService->update($id, $request->validated());
        return new CategoryResource($category);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->categoryService->delete($id);
        return response()->json(null, 204);
    }
}
