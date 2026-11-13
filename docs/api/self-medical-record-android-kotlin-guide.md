# Self Medical Record — Android API Integration Guide (Kotlin)

Dokumen ini adalah panduan implementasi frontend Android berbasis Kotlin untuk backend Self Medical Record. OpenAPI tetap menjadi kontrak lintas-platform; contoh fetching di bawah menggunakan Retrofit + OkHttp + Gson. Sesuaikan package, dependency version, dan pola arsitektur (MVVM/Repository) dengan project Android yang sudah ada.

## 1. File acuan

- `self-medical-record-openapi-1.2.0.yaml`: definisi endpoint, request, response, dan security.
- `self-medical-record-postman-collection-v2.1.json`: pengujian manual backend.
- Panduan ini: contoh konsumsi API dari Kotlin Android.

Jangan menyalin contoh JavaScript/TypeScript untuk aplikasi Android. Gunakan Kotlin interface, DTO, Repository, dan coroutine.

## 2. Base URL Android

Pilih base URL berdasarkan tempat aplikasi dijalankan:

| Target | Base URL Retrofit |
|---|---|
| Android Emulator, Laravel di host port 8000 | `http://10.0.2.2:8000/api/` |
| HP fisik pada Wi-Fi yang sama | `http://IP-LAN-KOMPUTER:8000/api/` |
| Production | `https://domain-api-sebenarnya/api/` |

Base URL Retrofit harus diakhiri `/`. Pada Android Emulator, `10.0.2.2` adalah alias ke loopback komputer host; `localhost` di dalam emulator menunjuk ke emulator itu sendiri.

Tambahkan permission internet di `AndroidManifest.xml`:

```xml
<uses-permission android:name="android.permission.INTERNET" />
```

Untuk development lokal dengan HTTP, Android 9/API 28+ memblokir cleartext secara default. Lebih baik aktifkan hanya pada debug build, misalnya buat `app/src/debug/AndroidManifest.xml`:

```xml
<manifest xmlns:android="http://schemas.android.com/apk/res/android">
    <application android:usesCleartextTraffic="true" />
</manifest>
```

Jangan aktifkan cleartext secara global untuk release/production. Production wajib menggunakan HTTPS.

## 3. Dependency

Periksa `gradle/libs.versions.toml` terlebih dahulu. Jika project belum memiliki library berikut, tambahkan dependency Retrofit, OkHttp, Gson converter, dan (untuk menampilkan foto remote) Coil. Gunakan versi stabil yang kompatibel dengan Gradle dan Kotlin project saat ini; jangan menambahkan versi duplikat apabila alias sudah tersedia.

Contoh dependency coordinates:

```kotlin
implementation("com.squareup.retrofit2:retrofit:<retrofit-version>")
implementation("com.squareup.retrofit2:converter-gson:<retrofit-version>")
implementation("com.squareup.okhttp3:okhttp:<okhttp-version>")
implementation("com.squareup.okhttp3:logging-interceptor:<okhttp-version>")
implementation("com.google.code.gson:gson:<gson-version>")
```

Jika menggunakan Jetpack Compose untuk image display, tambahkan Coil Compose sesuai versi yang dipakai project.

## 4. DTO / response models

Contoh model dasar mengikuti wrapper response yang muncul pada OpenAPI. Cocokkan properti opsional dan nama field terhadap response Laravel aktual.

```kotlin
import com.google.gson.annotations.SerializedName

 data class ApiEnvelope<T>(
    val success: Boolean,
    val message: String? = null,
    val data: T? = null
)

data class RoleDto(
    @SerializedName("role_id") val roleId: Int? = null,
    @SerializedName("role_name") val roleName: String? = null
)

data class UserDto(
    @SerializedName("user_id") val userId: Int,
    val name: String?,
    val email: String,
    val gender: String? = null,
    @SerializedName("date_of_birth") val dateOfBirth: String? = null,
    @SerializedName("avatar_url") val avatarUrl: String? = null,
    val role: RoleDto? = null
)

data class AuthData(
    val token: String,
    val user: UserDto
)

data class LoginRequest(
    val email: String,
    val password: String
)

data class HealthRecordRequest(
    val height: Double? = null,
    val weight: Double? = null,
    val systolic: Int? = null,
    val diastolic: Int? = null,
    @SerializedName("recorded_at") val recordedAt: String
)

data class HealthRecordDto(
    @SerializedName("health_record_id") val healthRecordId: Int,
    @SerializedName("user_id") val userId: Int,
    val height: String? = null,
    val weight: String? = null,
    val systolic: Int? = null,
    val diastolic: Int? = null,
    val bmi: String? = null,
    @SerializedName("bmi_category") val bmiCategory: String? = null,
    @SerializedName("bmi_message") val bmiMessage: String? = null,
    @SerializedName("warning_status") val warningStatus: String? = null,
    @SerializedName("warning_message") val warningMessage: String? = null,
    @SerializedName("recorded_at") val recordedAt: String? = null,
    @SerializedName("recorded_date") val recordedDate: String? = null
)

data class PersonalMedicalRecordRequest(
    @SerializedName("visit_date") val visitDate: String,
    @SerializedName("facility_name") val facilityName: String,
    val complaint: String,
    val medications: String? = null
)

data class PersonalMedicalRecordDto(
    @SerializedName("personal_medical_record_id") val personalMedicalRecordId: Int,
    @SerializedName("user_id") val userId: Int,
    @SerializedName("visit_date") val visitDate: String,
    @SerializedName("facility_name") val facilityName: String,
    val complaint: String,
    val medications: String? = null,
    val source: String? = null,
    @SerializedName("created_at") val createdAt: String? = null,
    @SerializedName("updated_at") val updatedAt: String? = null
)

data class LaravelPage<T>(
    @SerializedName("current_page") val currentPage: Int,
    val data: List<T>,
    val total: Int,
    @SerializedName("per_page") val perPage: Int
)
```

`height`, `weight`, dan `bmi` pada response HealthRecord dalam OpenAPI dicontohkan sebagai string desimal. Karena itu DTO response di atas memakai `String?`, sedangkan request input memakai `Double?`.

Untuk Dashboard dan Admin, gunakan DTO terpisah mengikuti response aktual. Jangan menebak struktur object Admin dari dokumentasi yang masih extensible; ambil response nyata melalui Postman/controller dan kemudian buat data class yang eksplisit.

## 5. Token storage dan OkHttp interceptor

Jangan hardcode token di aplikasi dan jangan commit token ke Git. Simpan token melalui penyimpanan lokal yang dilindungi Android Keystore/secure-storage mechanism yang dipilih project. Jangan menganggap SharedPreferences biasa terenkripsi.

Buat abstraksi agar interceptor tidak terikat langsung dengan implementasi storage:

```kotlin
interface TokenProvider {
    fun getAccessToken(): String?
}
```

Contoh interceptor:

```kotlin
import okhttp3.Interceptor
import okhttp3.Response

class AuthInterceptor(
    private val tokenProvider: TokenProvider
) : Interceptor {
    override fun intercept(chain: Interceptor.Chain): Response {
        val requestBuilder = chain.request().newBuilder()
            .header("Accept", "application/json")

        tokenProvider.getAccessToken()?.let { token ->
            requestBuilder.header("Authorization", "Bearer $token")
        }

        return chain.proceed(requestBuilder.build())
    }
}
```

Implementasi `TokenProvider` harus membaca token dari secure storage/cache yang aman dan tidak melakukan operasi blocking berat pada main thread.

## 6. Retrofit client

```kotlin
import okhttp3.OkHttpClient
import retrofit2.Retrofit
import retrofit2.converter.gson.GsonConverterFactory
import java.util.concurrent.TimeUnit

fun createApiRetrofit(
    baseUrl: String,
    tokenProvider: TokenProvider
): Retrofit {
    val client = OkHttpClient.Builder()
        .addInterceptor(AuthInterceptor(tokenProvider))
        .connectTimeout(20, TimeUnit.SECONDS)
        .readTimeout(30, TimeUnit.SECONDS)
        .writeTimeout(30, TimeUnit.SECONDS)
        .build()

    return Retrofit.Builder()
        .baseUrl(baseUrl) // wajib diakhiri '/'
        .client(client)
        .addConverterFactory(GsonConverterFactory.create())
        .build()
}
```

Jangan mencetak Authorization header atau token ke log. Jika memakai `HttpLoggingInterceptor`, matikan BODY/HEADERS logging pada release dan redact header `Authorization` pada debug.

## 7. Retrofit API interfaces

Path Retrofit relatif terhadap base URL yang sudah berakhiran `/api/`. Jangan menambahkan `/api` lagi di setiap annotation.

```kotlin
import okhttp3.MultipartBody
import retrofit2.Response
import retrofit2.http.*
import com.google.gson.JsonElement

interface AuthApi {
    @POST("login")
    suspend fun login(@Body body: LoginRequest): Response<ApiEnvelope<AuthData>>

    @POST("logout")
    suspend fun logout(): Response<ApiEnvelope<JsonElement>>

    @GET("me")
    suspend fun me(): Response<ApiEnvelope<UserDto>>
}

interface ProfileApi {
    @GET("me")
    suspend fun getProfile(): Response<ApiEnvelope<UserDto>>

    @Multipart
    @POST("profile/photo")
    suspend fun uploadProfilePhoto(
        @Part photo: MultipartBody.Part
    ): Response<ApiEnvelope<JsonElement>>

    @DELETE("profile/photo")
    suspend fun deleteProfilePhoto(): Response<ApiEnvelope<JsonElement>>
}

interface HealthRecordApi {
    @GET("health-records")
    suspend fun getHealthRecords(
        @Query("recorded_from") recordedFrom: String? = null,
        @Query("recorded_to") recordedTo: String? = null,
        @Query("warning_status") warningStatus: String? = null,
        @Query("per_page") perPage: Int = 15,
        @Query("page") page: Int? = null
    ): Response<ApiEnvelope<LaravelPage<HealthRecordDto>>>

    @POST("health-records")
    suspend fun createHealthRecord(
        @Body body: HealthRecordRequest
    ): Response<ApiEnvelope<HealthRecordDto>>

    @GET("health-records/{id}")
    suspend fun getHealthRecord(@Path("id") id: Int): Response<ApiEnvelope<HealthRecordDto>>

    @PUT("health-records/{id}")
    suspend fun updateHealthRecord(
        @Path("id") id: Int,
        @Body body: HealthRecordRequest
    ): Response<ApiEnvelope<HealthRecordDto>>

    @DELETE("health-records/{id}")
    suspend fun deleteHealthRecord(@Path("id") id: Int): Response<ApiEnvelope<JsonElement>>
}

interface CalorieApi {
    @Multipart
    @POST("calorie-estimation")
    suspend fun estimateCalories(
        @Part image: MultipartBody.Part
    ): Response<ApiEnvelope<JsonElement>>
}

interface AdminApi {
    @GET("admin/dashboard")
    suspend fun getDashboard(
        @Query("period") period: String = "month"
    ): Response<ApiEnvelope<JsonElement>>

    @GET("admin/users")
    suspend fun getUsers(
        @Query("per_page") perPage: Int = 15,
        @Query("page") page: Int = 1,
        @Query("search") search: String? = null,
        @Query("role") role: String? = null
    ): Response<ApiEnvelope<JsonElement>>

    @GET("admin/activity-logs")
    suspend fun getActivityLogs(
        @Query("per_page") perPage: Int = 20,
        @Query("action") action: String? = null,
        @Query("user_id") userId: Int? = null,
        @Query("date_from") dateFrom: String? = null,
        @Query("date_to") dateTo: String? = null
    ): Response<ApiEnvelope<JsonElement>>

    @GET("admin/openmrs/config")
    suspend fun getOpenMrsConfig(): Response<ApiEnvelope<JsonElement>>
}
```

Interface di atas adalah starter example, bukan pengganti pengecekan route final. Tambahkan endpoint lainnya berdasarkan OpenAPI dan route aktual. `JsonElement` sengaja dipakai sementara untuk beberapa response Admin yang skemanya di OpenAPI masih extensible; ganti dengan DTO bertipe setelah response controller diverifikasi.

## 8. Upload foto dari Android Uri

`Uri.path` tidak selalu merupakan filesystem path. Salin konten dari `ContentResolver` ke cache file terlebih dahulu:

```kotlin
import android.content.Context
import android.net.Uri
import okhttp3.MediaType.Companion.toMediaTypeOrNull
import okhttp3.MultipartBody
import okhttp3.RequestBody.Companion.asRequestBody
import java.io.File

fun uriToMultipart(
    context: Context,
    uri: Uri,
    fieldName: String
): MultipartBody.Part {
    val mimeType = context.contentResolver.getType(uri) ?: "image/jpeg"
    val extension = when (mimeType) {
        "image/png" -> ".png"
        "image/webp" -> ".webp"
        else -> ".jpg"
    }

    val tempFile = File.createTempFile("upload_", extension, context.cacheDir)
    context.contentResolver.openInputStream(uri).use { input ->
        requireNotNull(input) { "Cannot open selected image" }
        tempFile.outputStream().use { output -> input.copyTo(output) }
    }

    val requestBody = tempFile.asRequestBody(mimeType.toMediaTypeOrNull())
    return MultipartBody.Part.createFormData(fieldName, tempFile.name, requestBody)
}
```

Pemakaian:

```kotlin
val profilePart = uriToMultipart(context, selectedUri, "photo")
val profileResponse = profileApi.uploadProfilePhoto(profilePart)

val foodPart = uriToMultipart(context, selectedFoodUri, "image")
val calorieResponse = calorieApi.estimateCalories(foodPart)
```

Validasi file JPEG/PNG/WebP dan maksimal 5 MB juga di UI untuk pengalaman pengguna, tetapi validasi backend tetap menjadi sumber kebenaran. Bersihkan temporary cache file setelah request selesai bila sudah tidak diperlukan.

## 9. Signed URL foto makanan

Endpoint `GET /calorie-estimation/image/{filename}` menggunakan temporary signed URL. Backend mengembalikan URL lengkap pada response estimasi.

Aturan penting:
- Gunakan URL persis seperti yang diberikan backend.
- Jangan mengubah query `expires` atau `signature`.
- Jangan menambahkan Bearer token.
- Jangan membentuk signature sendiri.

Untuk menampilkan dengan Coil Compose:

```kotlin
AsyncImage(
    model = signedImageUrl,
    contentDescription = "Foto makanan"
)
```

Jika perlu mengunduh via Retrofit, gunakan OkHttp client terpisah tanpa `AuthInterceptor`, agar Bearer Token tidak ikut dikirim ke URL signed.

Hasil estimasi saat ini masih dummy. UI harus memeriksa `is_mock` dan menampilkan disclaimer.

## 10. Repository dan pemanggilan dari ViewModel

Contoh sederhana:

```kotlin
class AuthRepository(private val authApi: AuthApi) {
    suspend fun login(email: String, password: String): Result<AuthData> {
        return try {
            val response = authApi.login(LoginRequest(email, password))
            if (response.isSuccessful) {
                val data = response.body()?.data
                    ?: return Result.failure(IllegalStateException("Empty response data"))
                Result.success(data)
            } else {
                Result.failure(IllegalStateException("Login failed: HTTP ${response.code()}"))
            }
        } catch (exception: Exception) {
            Result.failure(exception)
        }
    }
}
```

Panggil repository dari `ViewModel` menggunakan `viewModelScope.launch`. Jangan melakukan network request di Main/UI thread. Untuk project yang sudah memakai sealed UI state atau Result wrapper sendiri, ikuti pola yang sudah ada.

## 11. Penanganan HTTP error

| HTTP | Makna | Perilaku Android |
|---|---|---|
| 401 | Token hilang/tidak valid | Bersihkan token dan arahkan ke login (kecuali endpoint login publik) |
| 403 | Role tidak memiliki izin | Tampilkan akses ditolak; jangan retry |
| 404 | Resource tidak ditemukan/tidak dimiliki | Tampilkan not found |
| 422 | Validasi gagal | Parse `errors` dan tampilkan per field |
| 429 | Rate limit | Hormati header `Retry-After` |
| 503 | OpenMRS tidak tersedia | Tampilkan layanan sementara tidak tersedia |

Jangan menganggap semua error sebagai kegagalan jaringan. Bedakan HTTP error, timeout, parsing error, dan koneksi terputus.

## 12. Role USER dan ADMIN

Login mengembalikan informasi role (konfirmasi lagi bentuk JSON aktual, laporan backend menyebut `user.role.role_name`). FE boleh memakai role untuk menampilkan navigasi yang sesuai:

```kotlin
val isAdmin = loginData.user.role?.roleName == "ADMIN"
```

Ini hanya kontrol tampilan. Backend tetap menjadi sumber otorisasi dan harus mengembalikan `403` untuk USER yang mengakses `/admin/...`.

Jika admin dashboard memang akan dibuat di Android yang sama, gunakan `AdminApi` di atas. Jika admin UI dibuat sebagai web terpisah, bagian Admin tidak perlu dimasukkan ke navigasi aplikasi Android pengguna.

## 13. Checklist sebelum integrasi

- [ ] Pastikan base URL emulator menggunakan `10.0.2.2`, bukan `localhost`.
- [ ] Pastikan base URL Retrofit berakhiran `/`.
- [ ] Tambahkan INTERNET permission.
- [ ] Izinkan cleartext hanya di debug untuk backend HTTP lokal.
- [ ] Login berhasil dan token tersimpan dengan aman.
- [ ] Interceptor mengirim Bearer token ke protected API.
- [ ] 401 dan 403 ditangani berbeda.
- [ ] DTO cocok dengan response aktual backend.
- [ ] Multipart field profile adalah `photo`.
- [ ] Multipart field food estimation adalah `image`.
- [ ] Signed URL dibuka tanpa Bearer token.
- [ ] Pagination dan query parameter diteruskan dengan benar.
- [ ] OpenMRS credentials tidak pernah disimpan di aplikasi Android.
- [ ] Dummy calorie result menampilkan disclaimer.

## 14. Hal yang harus diverifikasi di backend sebelum membuat DTO final

1. Jalankan `php artisan route:list --path=api` dan pastikan semua path/method cocok dengan OpenAPI.
2. Konfirmasi route Admin test connection: laporan final menyebut `/api/admin/test-connection`, sementara collection versi lebih lama pernah memiliki `/api/admin/openmrs/test-connection`.
3. Ambil response aktual dari semua endpoint Admin. Beberapa schema Admin pada OpenAPI sengaja extensible dan belum cukup rinci untuk menghasilkan DTO Kotlin final.
4. Konfirmasi bentuk role pada response login.
5. Konfirmasi wrapper pagination dan field meta pada setiap list endpoint.
6. Jangan menyalin contoh token, password, NIK, atau credential dari dokumentasi ke source code.

---

## Referensi

- Android Emulator networking: https://developer.android.com/studio/run/emulator-networking-address
- Android Network Security Configuration: https://developer.android.com/privacy-and-security/security-config
- Retrofit/OkHttp Kotlin interface patterns: https://github.com/JetBrains/skills/blob/main/kotlin-tooling-java-to-kotlin/references/frameworks/RETROFIT.md
