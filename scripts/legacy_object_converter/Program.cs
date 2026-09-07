using System.Runtime.Serialization;
using System.Runtime.Serialization.Formatters.Binary;
using System.Text.Json;
using System.Text.Json.Serialization;

// This utility is deliberately limited to trusted 3DPL project archives.
// BinaryFormatter must never be used with files supplied by untrusted users.

if (args.Length != 2)
{
    Console.Error.WriteLine("usage: LegacyObjectConverter SOURCE.obj DESTINATION.json");
    return 2;
}

var source = Path.GetFullPath(args[0]);
var destination = Path.GetFullPath(args[1]);

#pragma warning disable SYSLIB0011
using var stream = File.OpenRead(source);
var formatter = new BinaryFormatter { Binder = new LegacyTypeBinder() };
var value = formatter.Deserialize(stream);
#pragma warning restore SYSLIB0011

if (value is not List<Coordinates> coordinates)
    throw new SerializationException($"Expected List<Coordinates>, received {value?.GetType()}");

var voxels = coordinates
    .Where(item => !string.Equals(item.cubename, "AxisPoint", StringComparison.Ordinal))
    .Select(item => new Voxel(
        item.cubename ?? "",
        item.x,
        item.y,
        item.z,
        item.r,
        item.g,
        item.b,
        item.alpha,
        item.TextureName ?? "",
        item.WrapOnSides))
    .ToList();

Directory.CreateDirectory(Path.GetDirectoryName(destination)!);
var options = new JsonSerializerOptions
{
    WriteIndented = true,
    DefaultIgnoreCondition = JsonIgnoreCondition.Never,
};
File.WriteAllText(destination, JsonSerializer.Serialize(voxels, options) + Environment.NewLine);
return 0;

[Serializable]
public sealed class Coordinates
{
    public string? cubename;
    public float x;
    public float y;
    public float z;
    public float r;
    public float g;
    public float b;
    public string? TextureName;
    public string? TextureWebOrFile;
    public float alpha;
    public int WrapOnSides;
}

public sealed record Voxel(
    [property: JsonPropertyName("cubename")] string Cubename,
    [property: JsonPropertyName("x")] float X,
    [property: JsonPropertyName("y")] float Y,
    [property: JsonPropertyName("z")] float Z,
    [property: JsonPropertyName("r")] float R,
    [property: JsonPropertyName("g")] float G,
    [property: JsonPropertyName("b")] float B,
    [property: JsonPropertyName("alpha")] float Alpha,
    [property: JsonPropertyName("TextureName")] string TextureName,
    [property: JsonPropertyName("WrapOnSides")] int WrapOnSides);

public sealed class LegacyTypeBinder : SerializationBinder
{
    public override Type BindToType(string? assemblyName, string typeName)
    {
        if (typeName == "Coordinates")
            return typeof(Coordinates);
        if (typeName == "Coordinates[]")
            return typeof(Coordinates[]);
        if (typeName.StartsWith("System.Collections.Generic.List`1[[Coordinates,", StringComparison.Ordinal))
            return typeof(List<Coordinates>);

        var qualifiedName = assemblyName is null ? typeName : $"{typeName}, {assemblyName}";
        return Type.GetType(qualifiedName, throwOnError: true)!;
    }
}
